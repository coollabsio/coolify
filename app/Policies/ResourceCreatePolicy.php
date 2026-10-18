<?php

namespace App\Policies;

use App\Models\User;

class ResourceCreatePolicy
{
    /**
     * Determine whether the user can create any resource.
     */
    public function createAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
