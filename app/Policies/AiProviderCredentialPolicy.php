<?php

namespace App\Policies;

use App\Models\AiProviderCredential;
use App\Models\User;

class AiProviderCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AiProviderCredential $credential): bool
    {
        return $user->isAdmin() && $credential->team_id === currentTeam()->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AiProviderCredential $credential): bool
    {
        return $user->isAdmin() && $credential->team_id === currentTeam()->id;
    }

    public function delete(User $user, AiProviderCredential $credential): bool
    {
        return $user->isAdmin() && $credential->team_id === currentTeam()->id;
    }
}
