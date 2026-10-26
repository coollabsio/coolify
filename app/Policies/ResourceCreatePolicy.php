<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access\AccessResolver;

class ResourceCreatePolicy
{
    /**
     * Determine whether the user can create resources in the environment.
     * Without an environment, the user needs write access on the whole current team.
     */
    public function createAny(User $user, ?Environment $environment = null): bool
    {
        if ($environment !== null) {
            return $user->canAccess(AccessLevel::Write, $environment);
        }

        $teamId = $user->currentTeam()?->id;

        return $teamId !== null && app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Write, $teamId);
    }
}
