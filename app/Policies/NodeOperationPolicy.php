<?php

namespace App\Policies;

use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;

class NodeOperationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * The operation of a workload follows the `view` policy of the workload (a stack service: its stack,
     * a preview: its parent). An operation of a server or cluster, or of a deleted workload, needs read
     * on the whole team, as the server pages.
     */
    public function view(User $user, NodeOperation $nodeOperation): bool
    {
        $workload = $nodeOperation->node_workload_id === null ? null
            : NodeWorkload::query()->withoutGlobalScopes()->find($nodeOperation->node_workload_id);
        if ($workload !== null) {
            return $user->can('view', $workload);
        }

        return $nodeOperation->node !== null && $user->can('view', $nodeOperation->node);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, NodeOperation $nodeOperation): bool
    {
        return $user->isAdminOfTeam($nodeOperation->node->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, NodeOperation $nodeOperation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, NodeOperation $nodeOperation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, NodeOperation $nodeOperation): bool
    {
        return false;
    }
}
