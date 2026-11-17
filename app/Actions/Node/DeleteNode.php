<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Deletes a Node record, for the Node page and the API (decision 0029). The Node must be in no
 * cluster and run no applications. Coolify runs no command on the server: Sentinel and the data
 * stay on it. Only a team admin may delete a Node (`NodePolicy::delete`).
 */
class DeleteNode
{
    use AsAction;

    public const IN_USE_ERROR = 'Remove this server from its cluster and move or delete its applications before deleting it.';

    /** @throws DomainException When the Node is in a cluster or runs applications. */
    public function handle(Node $node, User $user): void
    {
        Gate::forUser($user)->authorize('delete', $node);
        $deleted = DB::transaction(function () use ($node): bool {
            $node = Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            if ($node->node_cluster_id !== null || $node->workloads()->exists()) {
                return false;
            }

            return (bool) $node->delete();
        });
        if (! $deleted) {
            throw new DomainException(self::IN_USE_ERROR);
        }
    }
}
