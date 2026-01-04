<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

trait DeletesUserSessions
{
    /**
     * Delete all sessions for the current user.
     * This will force the user to log in again on all devices.
     */
    public function deleteAllSessions(): void
    {
        // Invalidate the current session
        Session::invalidate();
        Session::regenerateToken();
        DB::table('sessions')->where('user_id', $this->id)->delete();
    }

    /**
     * Boot the trait.
     */
    protected static function bootDeletesUserSessions()
    {
        static::updated(function ($user) {
            // Check if password was changed
            if ($user->wasChanged('password')) {
                $user->deleteAllSessions();
            }
        });
    }
}
