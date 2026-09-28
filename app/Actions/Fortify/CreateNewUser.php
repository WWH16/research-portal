<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
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
        Validator::make($input, [
            ...$this->profileRules(),
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'password' => $this->passwordRules(),
        ], attributes: ['department_id' => __('department')])->validate();

        // Role is left out on purpose: sign-ups always get the default faculty role.
        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'department_id' => $input['department_id'],
            'password' => $input['password'],
        ]);
    }
}
