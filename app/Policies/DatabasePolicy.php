<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class DatabasePolicy
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
    public function view(User $user, $database): bool
    {
        // return $user->teams->contains('id', $database->team()->first()->id);
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
    public function update(User $user, $database)
    {
        // if ($user->isAdmin() && $user->teams->contains('id', $database->team()->first()->id)) {
        //    return Response::allow();
        // }

        // return Response::deny('As a member, you cannot update this database.<br/><br/>You need at least admin or owner permissions.');
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, $database): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $database->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, $database): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $database->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, $database): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $database->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can start/stop the database.
     */
    public function manage(User $user, $database): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $database->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can manage database backups.
     */
    public function manageBackups(User $user, $database): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $database->team()->first()->id);
        return true;
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, $database): bool
    {
        // return $user->isAdmin() && $user->teams->contains('id', $database->team()->first()->id);
        return true;
    }
}
