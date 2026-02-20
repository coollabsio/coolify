<?php

namespace App\Policies;

use App\Models\ApplicationDatabase;
use App\Models\Team;
use App\Models\User;

class ApplicationDatabasePolicy
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
    public function view(User $user, ApplicationDatabase $applicationDatabase): bool
    {
        return $user->belongsToTeam($applicationDatabase->team());
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ApplicationDatabase $applicationDatabase): bool
    {
        return $user->belongsToTeam($applicationDatabase->team());
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ApplicationDatabase $applicationDatabase): bool
    {
        return $user->belongsToTeam($applicationDatabase->team());
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ApplicationDatabase $applicationDatabase): bool
    {
        return $user->belongsToTeam($applicationDatabase->team());
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ApplicationDatabase $applicationDatabase): bool
    {
        return $user->belongsToTeam($applicationDatabase->team());
    }

    public function manageBackups(User $user, ApplicationDatabase $applicationDatabase): bool
    {
        return $user->belongsToTeam($applicationDatabase->team());
    }
}
