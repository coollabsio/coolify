<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NotificationPolicy
{
    /**
     * Determine whether the user can view the notification settings.
     */
    public function view(User $user, Model $notificationSettings): bool
    {
        // Check if the notification settings belong to the user's current team
        if (! $notificationSettings->team) {
            return false;
        }

        // return $user->teams()->where('teams.id', $notificationSettings->team->id)->exists();
        return true;
    }

    /**
     * Determine whether the user can update the notification settings.
     */
    public function update(User $user, Model $notificationSettings): bool
    {
        // Check if the notification settings belong to the user's current team
        if (! $notificationSettings->team) {
            return false;
        }

        // Only owners and admins can update notification settings
        //  return $user->isAdmin() || $user->isOwner();
        return true;
    }

    /**
     * Determine whether the user can manage (create, update, delete) notification settings.
     */
    public function manage(User $user, Model $notificationSettings): bool
    {
        // return $this->update($user, $notificationSettings);
        return true;
    }

    /**
     * Determine whether the user can send test notifications.
     */
    public function sendTest(User $user, Model $notificationSettings): bool
    {
        // return $this->update($user, $notificationSettings);
        return true;
    }
}
