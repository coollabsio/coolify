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
        $teamId = $this->getTeamId($applicationSetting);

        return $teamId !== null && $user->teams->contains('id', $teamId);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ApplicationSetting $applicationSetting): bool
    {
        $teamId = $this->getTeamId($applicationSetting);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ApplicationSetting $applicationSetting): bool
    {
        $teamId = $this->getTeamId($applicationSetting);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ApplicationSetting $applicationSetting): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ApplicationSetting $applicationSetting): bool
    {
        return false;
    }

    private function getTeamId(ApplicationSetting $applicationSetting): ?int
    {
        return $applicationSetting->application?->team()?->id;
    }
}
