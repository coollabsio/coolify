<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
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
        Validator::make($input, [
            'password' => ['required', Password::defaults(), 'confirmed'],
        ])->validate();

        // The reset link went to the mailbox, so its owner now holds the password.
        $user->fill([
            'password' => Hash::make($input['password']),
            'force_password_reset' => false,
        ])->forceFill(['created_by_invitation' => false])->save();
        $user->deleteAllSessions();
    }
}
