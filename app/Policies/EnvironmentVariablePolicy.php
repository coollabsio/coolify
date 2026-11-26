<?php

namespace App\Policies;

use App\Models\EnvironmentVariable;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * An environment variable is part of its resource's configuration: listing it needs view on the resource,
 * reading its value needs viewSensitive, and every change needs manageEnvironment (write access).
 */
class EnvironmentVariablePolicy
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
    public function view(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return $this->allowsOnResource($user, 'view', $environmentVariable);
    }

    /**
     * Determine whether the user can read the value.
     */
    public function viewSensitive(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return $this->allowsOnResource($user, 'viewSensitive', $environmentVariable);
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
    public function update(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return $this->allowsOnResource($user, 'manageEnvironment', $environmentVariable);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return $this->allowsOnResource($user, 'manageEnvironment', $environmentVariable);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return $this->allowsOnResource($user, 'manageEnvironment', $environmentVariable);
    }

    private function allowsOnResource(User $user, string $ability, EnvironmentVariable $environmentVariable): bool
    {
        $resource = $environmentVariable->resourceable;

        return $resource !== null && Gate::forUser($user)->allows($ability, $resource);
    }
}
