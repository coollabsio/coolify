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
        if (! $notificationSettings->team) {
            return false;
        }

        return $user->teams->contains('id', $notificationSettings->team->id);
    }

    /**
     * Determine whether the user can update the notification settings.
     */
    public function update(User $user, Model $notificationSettings): bool
    {
        if (! $notificationSettings->team) {
            return false;
        }

        $teamId = $notificationSettings->team->id;

        return $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can manage (create, update, delete) notification settings.
     */
    public function manage(User $user, Model $notificationSettings): bool
    {
        return $this->update($user, $notificationSettings);
    }

    /**
     * Determine whether the user can send test notifications.
     */
    public function sendTest(User $user, Model $notificationSettings): bool
    {
        return $this->update($user, $notificationSettings);
    }
}
