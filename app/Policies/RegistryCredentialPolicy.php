<?php

namespace App\Policies;

use App\Models\RegistryCredential;
use App\Models\User;

class RegistryCredentialPolicy
{
    /**
     * Listing and creating registry credentials happen on the session team's security pages.
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
     * A loaded credential is checked against its own team: the session team can differ.
     */
    public function view(User $user, RegistryCredential $registryCredential): bool
    {
        return $user->isAdminOfTeam((int) $registryCredential->team_id);
    }

    public function update(User $user, RegistryCredential $registryCredential): bool
    {
        return $user->isAdminOfTeam((int) $registryCredential->team_id);
    }

    public function delete(User $user, RegistryCredential $registryCredential): bool
    {
        return $user->isAdminOfTeam((int) $registryCredential->team_id);
    }
}
