<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default()];
    }

    /**
     * Get the validation rules for the password confirmation field.
     *
     * A mismatch is reported on this field, under Confirm password, instead of on the password through `confirmed`.
     *
     * @return array<int, string>
     */
    protected function passwordConfirmationRules(): array
    {
        return ['required', 'same:password'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
