<?php

namespace App\Policies;

use App\Models\ServiceApplication;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ServiceApplicationPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ServiceApplication $serviceApplication): bool
    {
        return Gate::allows('view', $serviceApplication->service);
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
    public function update(User $user, ServiceApplication $serviceApplication): bool
    {
        return Gate::allows('update', $serviceApplication->service);
    }

    /**
     * Determine whether the user can deploy or run lifecycle actions on the parent service stack.
     */
    public function deploy(User $user, ServiceApplication $serviceApplication): bool
    {
        return Gate::allows('deploy', $serviceApplication->service);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ServiceApplication $serviceApplication): bool
    {
        return Gate::allows('delete', $serviceApplication->service);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ServiceApplication $serviceApplication): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ServiceApplication $serviceApplication): bool
    {
        return false;
    }
}
