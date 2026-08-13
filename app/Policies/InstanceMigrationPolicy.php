<?php

namespace App\Policies;

use App\Models\InstanceMigration;
use App\Models\User;

class InstanceMigrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, InstanceMigration $instanceMigration): bool
    {
        return $user->teams->contains('id', $instanceMigration->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, InstanceMigration $instanceMigration): bool
    {
        return $user->isAdminOfTeam($instanceMigration->team_id);
    }

    public function delete(User $user, InstanceMigration $instanceMigration): bool
    {
        return $user->isAdminOfTeam($instanceMigration->team_id);
    }
}
