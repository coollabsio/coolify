<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\NodeWorkload;
use App\Models\User;
use App\Services\Access\AccessResolver;

/**
 * A preview follows its parent (decision 0025): a user who can view the parent can view its
 * previews, and a user who can deploy the parent can deploy and delete them. A preview without
 * its parent allows nothing.
 */
class NodeWorkloadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NodeWorkload $nodeWorkload): bool
    {
        $workload = $this->governing($nodeWorkload);
        if ($workload === null) {
            return false;
        }

        // A workload in an environment follows the access grants there, like other resources.
        return $workload->environment_id !== null
            ? $user->canAccess(AccessLevel::Read, $workload)
            : app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $workload->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->isAdminOfGoverningTeam($user, $nodeWorkload);
    }

    public function delete(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->isAdminOfGoverningTeam($user, $nodeWorkload);
    }

    /**
     * Determine whether the user can view sensitive values, such as environment variable values.
     */
    public function viewSensitive(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->isAdminOfGoverningTeam($user, $nodeWorkload);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, NodeWorkload $nodeWorkload): bool
    {
        return $this->isAdminOfGoverningTeam($user, $nodeWorkload);
    }

    private function isAdminOfGoverningTeam(User $user, NodeWorkload $nodeWorkload): bool
    {
        $workload = $this->governing($nodeWorkload);

        return $workload !== null && $user->isAdminOfTeam($workload->team_id);
    }

    /** The workload whose access applies: the parent of a preview, else the workload itself. */
    private function governing(NodeWorkload $nodeWorkload): ?NodeWorkload
    {
        return $nodeWorkload->isPreview() ? $nodeWorkload->previewOf : $nodeWorkload;
    }
}
