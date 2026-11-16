<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Environment;
use App\Models\NodeStack;
use App\Models\User;

/** The rules of NodeWorkloadPolicy: the services of a stack are cluster applications with the access of the stack. */
class NodeStackPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeStack $nodeStack): bool
    {
        return NodeWorkloadPolicy::allowsOn($user, AccessLevel::Read, $nodeStack);
    }

    /**
     * As a v4 service: write access on the environment, or on the whole current team without one.
     * A stack that mounts the Docker socket also needs an admin (NodeStackCompose::ensureMayMountContainerSocket()).
     */
    public function create(User $user, ?Environment $environment = null): bool
    {
        return app(ResourceCreatePolicy::class)->createAny($user, $environment);
    }

    /** Every change of the compose file and the settings. */
    public function update(User $user, NodeStack $nodeStack): bool
    {
        return NodeWorkloadPolicy::allowsOn($user, AccessLevel::Write, $nodeStack);
    }

    public function deploy(User $user, NodeStack $nodeStack): bool
    {
        return NodeWorkloadPolicy::allowsOn($user, AccessLevel::Deploy, $nodeStack);
    }

    public function delete(User $user, NodeStack $nodeStack): bool
    {
        return NodeWorkloadPolicy::allowsOn($user, AccessLevel::Delete, $nodeStack);
    }
}
