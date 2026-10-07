<?php

namespace App\Policies;

use App\Models\IntegrationToken;
use App\Models\User;

class IntegrationTokenPolicy
{
    /**
     * Listing and creating tokens happen on the session team's security pages.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * A loaded token is checked against its own team: the session team can differ.
     */
    public function view(User $user, IntegrationToken $integrationToken): bool
    {
        return $user->isAdminOfTeam((int) $integrationToken->team_id);
    }

    public function update(User $user, IntegrationToken $integrationToken): bool
    {
        return $user->isAdminOfTeam((int) $integrationToken->team_id);
    }

    public function delete(User $user, IntegrationToken $integrationToken): bool
    {
        return $user->isAdminOfTeam((int) $integrationToken->team_id);
    }
}
