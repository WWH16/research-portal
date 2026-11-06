<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Every try counts, failed ones too, so a script can't keep testing which emails already have an account; one that
        // passes also sends an email, and the portal's Gmail account sends only so many a day.
        // ponytail: counted per network address, which a campus shares, so 30 an hour; raise it if a college signs up together.
        $key = 'sign-up:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages(['email' => __('Too many sign-ups have come from your network. Please try again within the hour.')]);
        }

        RateLimiter::hit($key, 3600);

        Validator::make($input, [
            ...$this->profileRules(),
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'password' => $this->passwordRules(),
            'password_confirmation' => $this->passwordConfirmationRules(),
        ], [
            'name.required' => __('Enter your full name.'),
            'department_id.required' => __('Choose your college.'),
            'department_id' => __('Choose a college from the list.'),
        ])->validate();

        // Role is left out on purpose: sign-ups always get the default faculty role.
        return User::createRetryingResearcherId(fn () => User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'department_id' => $input['department_id'],
            'password' => $input['password'],
        ]));
    }
}
