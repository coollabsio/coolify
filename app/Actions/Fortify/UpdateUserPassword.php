<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        // OAuth-only users cannot set/update passwords
        if ($user->is_oauth_only) {
            throw ValidationException::withMessages([
                'password' => ['OAuth-only users cannot set a password. Please use your OAuth provider to login.'],
            ]);
        }

        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
