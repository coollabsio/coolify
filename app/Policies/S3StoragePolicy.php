<?php

namespace App\Policies;

use App\Models\S3Storage;
use App\Models\Server;
use App\Models\User;

class S3StoragePolicy
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
    public function view(User $user, S3Storage $storage): bool
    {
        return $user->teams()->get()->firstWhere('id', $storage->team_id) !== null;
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
    public function update(User $user, Server $server): bool
    {
        return $user->teams()->get()->firstWhere('id', $server->team_id) !== null;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, S3Storage $storage): bool
    {
        return $user->teams()->get()->firstWhere('id', $storage->team_id) !== null;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, S3Storage $storage): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, S3Storage $storage): bool
    {
        return false;
    }
}
