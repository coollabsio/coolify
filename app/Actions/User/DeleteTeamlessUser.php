<?php

namespace App\Actions\User;

use App\Models\OauthIdentity;
use App\Models\User;
use App\Models\UserChangelogRead;
use App\Services\AvatarStorageService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes a user that belongs to no team anymore, with their avatar, sign-in identities,
 * sessions, and password reset tokens.
 */
class DeleteTeamlessUser
{
    public function handle(User $user): void
    {
        try {
            app(AvatarStorageService::class)->delete($user);
        } catch (Throwable $e) {
            report($e);
        }

        OauthIdentity::query()->where('user_id', $user->id)->delete();
        UserChangelogRead::query()->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        $user->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
