<?php

namespace App\Policies;

use App\Models\ScheduledTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ScheduledTaskPolicy
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
    public function view(User $user, ScheduledTask $scheduledTask): bool
    {
        return $user->teams->contains('id', $scheduledTask->team_id);
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
    public function update(User $user, ScheduledTask $scheduledTask): Response
    {
        if (! $user->isAdminOfTeam($scheduledTask->team_id)) {
            return Response::deny('You need at least admin or owner permissions to update this scheduled task.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ScheduledTask $scheduledTask): bool
    {
        return $user->isAdminOfTeam($scheduledTask->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ScheduledTask $scheduledTask): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ScheduledTask $scheduledTask): bool
    {
        return false;
    }
}
