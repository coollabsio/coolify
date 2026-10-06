<?php

namespace App\Jobs;

use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeWorkload;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Deletes a cluster application like v4 deletes a resource: first its container on every
 * server, then its records. When a server cannot remove the container, nothing is deleted and
 * the team is notified, like a v4 service whose server is unreachable. The application then
 * keeps the desired state `removed`, so a server that reconnects removes the container itself.
 */
class DeleteNodeWorkloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $workloadId,
        public ?int $requestedById = null,
        public bool $deleteFromCoolifyOnly = false,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $workload = NodeWorkload::query()->with('nodes')->find($this->workloadId);
        if ($workload === null) {
            return;
        }
        $requestedBy = $this->requestedById === null ? null : User::query()->find($this->requestedById);

        if (! $this->deleteFromCoolifyOnly) {
            $failures = $this->removeContainers($workload, $requestedBy);
            if ($failures !== []) {
                $this->reportFailure($workload, $failures);

                throw new RuntimeException("Application {$workload->name} could not be deleted: ".implode(' ', $failures));
            }
        }

        $this->deleteRecords($workload, $requestedBy);
    }

    /**
     * Removes the container on each server and waits for the result.
     *
     * @return list<string> One message per server that did not remove the container.
     */
    private function removeContainers(NodeWorkload $workload, ?User $requestedBy): array
    {
        $revision = $workload->revisions()->latest('id')->first();
        if ($revision === null) {
            return [];
        }

        $failures = [];
        foreach ($workload->nodes->sortBy('id') as $node) {
            try {
                $operation = CreateLifecycleOperation::run($node, $revision, NodeWorkloadAction::REMOVE, $requestedBy);
                (new ManageNodeWorkloadJob($operation->id))->handle();
                $operation->refresh();
                if ($operation->status !== NodeOperationStatus::SUCCEEDED) {
                    $failures[] = "{$node->name}: ".($operation->error ?? 'The container could not be removed.');
                }
            } catch (Throwable $exception) {
                $failures[] = "{$node->name}: {$exception->getMessage()}";
            }
        }

        return $failures;
    }

    private function deleteRecords(NodeWorkload $workload, ?User $requestedBy): void
    {
        // Clusters whose network intent references the workload: ingress routes and firewall rules.
        $clusterIds = collect($workload->hasIngressRoutes() ? $workload->clusterIds() : [])
            ->merge(NodeFirewallRule::query()
                ->where('source_workload_id', $workload->id)
                ->orWhere('destination_workload_id', $workload->id)
                ->pluck('node_cluster_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($workload): void {
            $workload->nodes()->detach();
            NodeFirewallRule::query()
                ->where('source_workload_id', $workload->id)
                ->orWhere('destination_workload_id', $workload->id)
                ->delete();
            $workload->environment_variables()->delete();
            // Revisions cascade with the workload; operations and container inventory keep their
            // history with a null workload (see the node_operations and node_containers foreign keys).
            $workload->delete();
        });

        // The placements are already detached, so the model event cannot find these clusters.
        NodeCluster::query()
            ->whereKey($clusterIds)
            ->get()
            ->each(fn (NodeCluster $cluster) => QueueNodeClusterNetworkRevision::run($cluster, $requestedBy));
    }

    /** @param list<string> $failures */
    private function reportFailure(NodeWorkload $workload, array $failures): void
    {
        Log::warning('Cluster application deletion stopped because a container could not be removed.', [
            'workload_uuid' => $workload->uuid,
            'failures' => $failures,
        ]);

        $servers = $workload->nodes->pluck('name')->implode("', '");
        Team::query()->find($workload->team_id)?->notify(new GeneralNotification(
            "Application deletion failed for '{$workload->name}'. Its container may still exist on server '{$servers}'. "
            ."You can retry the deletion or select 'Remove from Coolify only' in the deletion dialog. Error: ".implode(' ', $failures),
            success: false,
        ));
    }
}
