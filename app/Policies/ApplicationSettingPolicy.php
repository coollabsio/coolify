<?php

namespace App\Policies;

use App\Models\ApplicationSetting;
use App\Models\User;

class ApplicationSettingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ApplicationSetting $applicationSetting): bool
    {
        // return $user->teams->contains('id', $applicationSetting->application->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // return $user->isAdmin();
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ApplicationSetting $applicationSetting): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $applicationSetting->application->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ApplicationSetting $applicationSetting): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $applicationSetting->application->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ApplicationSetting $applicationSetting): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $applicationSetting->application->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ApplicationSetting $applicationSetting): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $applicationSetting->application->team()->first()->id);
        return true;
    }
}
