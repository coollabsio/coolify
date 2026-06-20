<?php

namespace App\Policies;

use App\Models\ServiceDatabase;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ServiceDatabasePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::allows('view', $serviceDatabase->service);
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
    public function update(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::allows('update', $serviceDatabase->service);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::allows('delete', $serviceDatabase->service);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage database backups.
     */
    public function manageBackups(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::allows('update', $serviceDatabase->service);
    }

    /**
     * Determine whether the user can upload a backup archive for this service database.
     */
    public function uploadBackup(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::allows('uploadBackup', $serviceDatabase->service);
    }
}
