<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteNodeCluster
{
    use AsAction;

    public function handle(NodeCluster $cluster, User $user): void
    {
        Gate::forUser($user)->authorize('delete', $cluster);
        $cluster->load('nodes');

        if ($cluster->nodes()->whereHas('workloads')->exists()) {
            throw new DomainException('Remove all workloads from this cluster before deleting it.');
        }

        foreach ($cluster->nodes()->orderBy('id')->get() as $node) {
            RemoveNodeFromCluster::run($cluster->fresh(), $node, $user, reconcileSurvivors: false);
        }

        $cluster->fresh()?->delete();
    }
}
