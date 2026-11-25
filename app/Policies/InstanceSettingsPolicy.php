<?php

namespace App\Policies;

use App\Models\InstanceSettings;
use App\Models\User;

class InstanceSettingsPolicy
{
    /**
     * Determine whether the user can view the instance settings.
     */
    public function view(User $user, InstanceSettings $settings): bool
    {
        return isInstanceAdmin();
    }

    /**
     * Determine whether the user can update the instance settings.
     */
    public function update(User $user, InstanceSettings $settings): bool
    {
        return isInstanceAdmin();
    }
}
