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
    public function update(User $user, ServiceDatabase $serviceDatabase): bool
    {

        // return Gate::allows('update', $serviceDatabase->service);
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        // return Gate::allows('delete', $serviceDatabase->service);
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ServiceDatabase $serviceDatabase): bool
    {
        // return Gate::allows('update', $serviceDatabase->service);
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        // return Gate::allows('delete', $serviceDatabase->service);
        return true;
    }

    public function manageBackups(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return true;
    }
}
