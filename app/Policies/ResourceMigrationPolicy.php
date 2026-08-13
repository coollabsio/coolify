<?php

namespace App\Policies;

use App\Models\ResourceMigration;
use App\Models\User;

class ResourceMigrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ResourceMigration $resourceMigration): bool
    {
        return $user->teams->contains('id', $resourceMigration->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ResourceMigration $resourceMigration): bool
    {
        return $user->isAdminOfTeam($resourceMigration->team_id);
    }

    public function delete(User $user, ResourceMigration $resourceMigration): bool
    {
        return $user->isAdminOfTeam($resourceMigration->team_id);
    }
}
