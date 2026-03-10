<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        $settings = instanceSettings();
        // Prevent OAuth-only users from resetting passwords
        if ($settings->oauth_only || $user->oauth_only) {
            throw ValidationException::withMessages([
                'email' => __('Password reset is disabled for OAuth-only accounts.'),
            ]);
        }

        Validator::make($input, [
            'password' => ['required', Password::defaults(), 'confirmed'],
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
        $user->deleteAllSessions();
    }
}
