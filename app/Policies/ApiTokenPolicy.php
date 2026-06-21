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
        // Authorization temporarily disabled
        /*
        // Users can view their own API tokens
        return true;
        */
        return true;
    }

    /**
     * Determine whether the user can view the API token.
     */
    public function view(User $user, PersonalAccessToken $token): bool
    {
        // Authorization temporarily disabled
        /*
        // Users can only view their own tokens
        return $user->id === $token->tokenable_id && $token->tokenable_type === User::class;
        */
        return true;
    }

    /**
     * Determine whether the user can create API tokens.
     */
    public function create(User $user): bool
    {
        // Authorization temporarily disabled
        /*
        // All authenticated users can create their own API tokens
        return true;
        */
        return true;
    }

    /**
     * Determine whether the user can update the API token.
     */
    public function update(User $user, PersonalAccessToken $token): bool
    {
        // Authorization temporarily disabled
        /*
        // Users can only update their own tokens
        return $user->id === $token->tokenable_id && $token->tokenable_type === User::class;
        */
        return true;
    }

    /**
     * Determine whether the user can delete the API token.
     */
    public function delete(User $user, PersonalAccessToken $token): bool
    {
        // Authorization temporarily disabled
        /*
        // Users can only delete their own tokens
        return $user->id === $token->tokenable_id && $token->tokenable_type === User::class;
        */
        return true;
    }

    /**
     * Determine whether the user can manage their own API tokens.
     */
    public function manage(User $user): bool
    {
        // Authorization temporarily disabled
        /*
        // All authenticated users can manage their own API tokens
        return true;
        */
        return true;
    }

    /**
     * Determine whether the user can use root permissions for API tokens.
     */
    public function useRootPermissions(User $user): bool
    {
        // Only admins and owners can use root permissions
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can use write permissions for API tokens.
     */
    public function useWritePermissions(User $user): bool
    {
        // Authorization temporarily disabled
        /*
        // Only admins and owners can use write permissions
        return $user->isAdmin() || $user->isOwner();
        */
        return true;
    }
}
