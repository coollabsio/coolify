<?php

namespace App\Policies;

use App\Models\IntegrationToken;
use App\Models\User;

class IntegrationTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, IntegrationToken $integrationToken): bool
    {
        return $user->isAdmin() && $integrationToken->team_id === currentTeam()->id;
    }

    public function update(User $user, IntegrationToken $integrationToken): bool
    {
        return $user->isAdmin() && $integrationToken->team_id === currentTeam()->id;
    }

    public function delete(User $user, IntegrationToken $integrationToken): bool
    {
        return $user->isAdmin() && $integrationToken->team_id === currentTeam()->id;
    }
}
