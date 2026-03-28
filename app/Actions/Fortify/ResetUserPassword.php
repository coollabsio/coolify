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
        if (($settings->is_oauth_only_enabled ?? false) && is_null($user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Password reset is disabled for OAuth-only accounts. Please sign in with your OAuth provider.',
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
