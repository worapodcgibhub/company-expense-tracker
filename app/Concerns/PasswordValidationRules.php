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
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Strong password rules applied in every environment (the app-wide
     * default is only strict in production). Used when the Owner sets a
     * password for another user. `uncompromised()` is intentionally not
     * used: it calls an external API.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function strongPasswordRules(): array
    {
        return [
            'required',
            'string',
            Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
            'confirmed',
        ];
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
