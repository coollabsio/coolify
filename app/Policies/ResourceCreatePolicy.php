<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Determine whether the user can use a team credential or team-wide location that the resource does not use
     * yet: private keys, git sources, integration tokens, and backup files of other resources.
     * Needs write access on the whole team of the resource, or of the current team without a resource.
     */
    public function useTeamCredentials(User $user, ?Model $resource = null): Response
    {
        $teamId = $resource !== null ? $resource->team()?->id : $user->currentTeam()?->id;

        return $teamId !== null && app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Write, (int) $teamId)
            ? Response::allow()
            : Response::deny('You need write access on the whole team to use team credentials, such as private keys, git sources, and S3 storages.');
    }
}
