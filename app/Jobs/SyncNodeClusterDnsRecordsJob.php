<?php

namespace App\Jobs;

use App\Models\DnsProviderZone;
use App\Models\ManagedDnsRecordReference;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Services\Dns\NodeWorkloadDnsRecords;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Converges the managed DNS records of the cluster applications of one cluster (decision 0019), after a change of
 * their domains or placements, or of the ingress Nodes. A change while a run is in progress queues the next run.
 */
class SyncNodeClusterDnsRecordsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $clusterId) {}

    public function uniqueId(): string
    {
        return (string) $this->clusterId;
    }

    public function handle(NodeWorkloadDnsRecords $records): void
    {
        $cluster = NodeCluster::query()->find($this->clusterId);
        if ($cluster === null || ! $this->teamUsesManagedDns($cluster)) {
            return;
        }

        $records->syncCluster($cluster);
    }

    /** A team without a DNS zone and without managed cluster records has nothing to converge. */
    private function teamUsesManagedDns(NodeCluster $cluster): bool
    {
        return DnsProviderZone::query()->whereHas('integrationToken', fn ($query) => $query->where('team_id', $cluster->team_id))->exists()
            || ManagedDnsRecordReference::query()
                ->where('resource_type', (new NodeWorkload)->getMorphClass())
                ->whereHas('record', fn ($query) => $query->where('team_id', $cluster->team_id))
                ->exists();
    }
}
