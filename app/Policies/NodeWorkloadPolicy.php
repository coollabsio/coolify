<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Environment;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\User;
use App\Services\Access\AccessResolver;

/**
 * The levels of ApplicationPolicy. A preview follows its parent (decision 0025) and a stack service
 * its stack: the access grants of that resource apply. A preview without its parent allows nothing.
 */
class NodeWorkloadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->allows($user, AccessLevel::Read, $nodeWorkload);
    }

    /**
     * As a v4 application: write access on the environment, or on the whole current team without
     * one. The create actions also check the placement (`NodePolicy::placeResource`).
     */
    public function create(User $user, ?Environment $environment = null): bool
    {
        return app(ResourceCreatePolicy::class)->createAny($user, $environment);
    }

    /** Every change of the settings. */
    public function update(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->allows($user, AccessLevel::Write, $nodeWorkload);
    }

    /** Deploy, rollback, restart, stop, and cancel a build. */
    public function deploy(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->allows($user, AccessLevel::Deploy, $nodeWorkload);
    }

    public function delete(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->allows($user, AccessLevel::Delete, $nodeWorkload);
    }

    /**
     * Determine whether the user can view sensitive values, such as environment variable values.
     */
    public function viewSensitive(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->allows($user, AccessLevel::ReadSensitive, $nodeWorkload);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->allows($user, AccessLevel::Write, $nodeWorkload);
    }

    private function allows(User $user, AccessLevel $level, NodeWorkload $nodeWorkload): bool
    {
        return self::allowsOn($user, $level, $nodeWorkload->accessTarget());
    }

    /** A resource in an environment follows the access grants there, one without an environment the team-wide grants. */
    public static function allowsOn(User $user, AccessLevel $level, NodeWorkload|NodeStack|null $resource): bool
    {
        if ($resource === null) {
            return false;
        }

        return $resource->environment_id !== null
            ? $user->canAccess($level, $resource)
            : app(AccessResolver::class)->allowsTeamWide($user, $level, $resource->team_id);
    }
}
