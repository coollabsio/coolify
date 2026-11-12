<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Publishes a network revision, applies it in this worker, and waits until every reachable Node
 * applied it up to the ingress step. Nodes that go offline meanwhile are no longer waited for.
 */
class ApplyNodeClusterNetworkRevision
{
    use AsAction;

    public const WAIT_SECONDS = 120;

    public function handle(NodeCluster $cluster, ?User $user): void
    {
        $nodes = $cluster->nodes()->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $node->canReceiveNetworkCommands());
        $attempts = $nodes->pluck('network_attempts', 'id');
        if (! QueueNodeClusterNetworkRevision::run($cluster, $user)) {
            // The network does not converge automatically: no run would apply the revision.
            return;
        }
        $revision = $cluster->desired_revision;
        // The queued run can wait behind this job when Horizon has one worker; it then finds the
        // Nodes converged. Another run holding the lock is waited for below.
        $operator = $user !== null && $user->can('update', $cluster) ? $user : $cluster->networkOperator();
        if ($operator !== null) {
            try {
                ReconcileNodeClusterNetwork::run($cluster, $operator, $nodes->pluck('id')->values()->all());
            } catch (LockTimeoutException) {
            }
        }

        for ($poll = 0; $poll <= self::WAIT_SECONDS; $poll++) {
            $pending = Node::query()->whereKey($nodes->pluck('id'))->orderBy('id')->get()
                ->filter(fn (Node $node): bool => $node->canReceiveNetworkCommands() && ! $node->hasAppliedNetworkRevision($revision));
            $failed = $pending->first(fn (Node $node): bool => $node->network_status === 'error'
                && $node->network_attempts > (int) $attempts->get($node->id));
            if ($failed !== null) {
                throw new RuntimeException("Server {$failed->name} could not apply the cluster network: {$failed->network_error}");
            }
            if ($pending->isEmpty()) {
                return;
            }
            if ($poll < self::WAIT_SECONDS) {
                Sleep::for(1)->second();
            }
        }

        throw new RuntimeException('The cluster network did not apply within '.self::WAIT_SECONDS.' seconds. Waiting for: '.$pending->pluck('name')->implode(', ').'.');
    }
}
