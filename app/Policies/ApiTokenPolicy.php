<?php

namespace App\Policies;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenPolicy
{
    /**
     * Determine whether the user can view any API tokens.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the API token.
     */
    public function view(User $user, PersonalAccessToken $token): bool
    {
        return $user->id === $token->tokenable_id && $token->tokenable_type === User::class;
    }

    /**
     * Determine whether the user can create API tokens.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the API token.
     */
    public function update(User $user, PersonalAccessToken $token): bool
    {
        return $user->id === $token->tokenable_id && $token->tokenable_type === User::class;
    }

    /**
     * Determine whether the user can delete the API token.
     */
    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $user->id === $token->tokenable_id && $token->tokenable_type === User::class;
    }

    /**
     * Determine whether the user can manage their own API tokens.
     */
    public function manage(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can use root permissions for API tokens.
     */
    public function useRootPermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can use write permissions for API tokens.
     */
    public function useWritePermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can use deploy permissions for API tokens.
     */
    public function useDeployPermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can use read:sensitive permissions for API tokens.
     */
    public function useSensitivePermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }
}
