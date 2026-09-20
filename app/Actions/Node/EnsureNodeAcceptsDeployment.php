<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeWorkloadRevision;
use DomainException;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

class EnsureNodeAcceptsDeployment
{
    use AsAction;

    public function handle(Node $node, ?NodeWorkloadRevision $revision = null): void
    {
        if (! $node->is_usable) {
            throw new DomainException("Node {$node->name} is not usable.");
        }
        if (! $node->is_reachable) {
            throw new DomainException("Node {$node->name} is not reachable.");
        }

        $cluster = $node->cluster;
        if ($cluster === null) {
            throw new DomainException("Node {$node->name} does not belong to a cluster.");
        }

        $metadata = is_array($node->metadata) ? $node->metadata : [];
        $maximumAge = $cluster->resource_stale_after_minutes;
        try {
            $collectedAt = Carbon::parse($metadata['collected_at'] ?? '');
        } catch (\Throwable) {
            throw new DomainException("Node {$node->name} resource data is unavailable.");
        }
        if ($collectedAt->isBefore(now()->subMinutes($maximumAge))) {
            throw new DomainException("Node {$node->name} resource data is older than {$maximumAge} minutes.");
        }

        $cpu = $this->number($metadata, 'cpu_usage_percent');
        $memoryTotal = $this->positiveNumber($metadata, 'memory_bytes');
        $memoryUsed = $this->number($metadata, 'memory_used_bytes');
        $diskTotal = $this->positiveNumber($metadata, 'disk_total_bytes');
        $diskAvailable = $this->number($metadata, 'disk_available_bytes');
        if ($cpu === null || $memoryTotal === null || $memoryUsed === null || $diskTotal === null || $diskAvailable === null) {
            throw new DomainException("Node {$node->name} resource data is incomplete.");
        }

        $this->rejectAtLimit('CPU', $cpu, $cluster->cpu_pressure_threshold, $node);
        $this->rejectAtLimit('memory', ($memoryUsed / $memoryTotal) * 100, $cluster->memory_pressure_threshold, $node);
        $this->rejectAtLimit('disk', (($diskTotal - min($diskAvailable, $diskTotal)) / $diskTotal) * 100, $cluster->disk_pressure_threshold, $node);

        if ($revision !== null) {
            $this->ensureReservationsFit($node, $revision, $memoryTotal);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function number(array $metadata, string $key): ?float
    {
        $value = $metadata[$key] ?? null;

        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    /** @param array<string, mixed> $metadata */
    private function positiveNumber(array $metadata, string $key): ?float
    {
        $value = $this->number($metadata, $key);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function rejectAtLimit(string $resource, float $usage, int $limit, Node $node): void
    {
        if ($usage >= $limit) {
            $formattedUsage = number_format($usage, 1);

            throw new DomainException("Node {$node->name} has {$resource} pressure: {$formattedUsage}% usage reached the {$limit}% deployment limit.");
        }
    }

    private function ensureReservationsFit(Node $node, NodeWorkloadRevision $revision, float $memoryTotal): void
    {
        $requested = data_get($revision->configuration, 'resources', []);
        $cpuReservation = (float) data_get($requested, 'cpu_reservation', 0);
        $memoryReservation = (int) data_get($requested, 'memory_reservation_bytes', 0);
        $workloads = $node->workloads()
            ->whereKeyNot($revision->node_workload_id)
            ->where('desired_state', 'running')
            ->with(['revisions' => fn ($query) => $query->latest('id')->limit(1)])
            ->get();
        foreach ($workloads as $workload) {
            $resources = data_get($workload->revisions->first()?->configuration, 'resources', []);
            $cpuReservation += (float) data_get($resources, 'cpu_reservation', 0);
            $memoryReservation += (int) data_get($resources, 'memory_reservation_bytes', 0);
        }

        if ($cpuReservation > 0) {
            $cpuCount = $this->positiveNumber(is_array($node->metadata) ? $node->metadata : [], 'cpus');
            if ($cpuCount === null) {
                throw new DomainException("Node {$node->name} CPU capacity is unavailable for this reservation.");
            }
            $availableCpuReservation = $cpuCount * ($node->cluster->cpu_pressure_threshold / 100);
            if ($cpuReservation > $availableCpuReservation) {
                throw new DomainException("Node {$node->name} does not have enough CPU reservation capacity.");
            }
        }
        $availableMemoryReservation = $memoryTotal * ($node->cluster->memory_pressure_threshold / 100);
        if ($memoryReservation > $availableMemoryReservation) {
            throw new DomainException("Node {$node->name} does not have enough memory reservation capacity.");
        }
    }
}
