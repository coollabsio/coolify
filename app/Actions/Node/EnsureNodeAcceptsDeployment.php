<?php

namespace App\Actions\Node;

use App\Models\Node;
use DomainException;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

class EnsureNodeAcceptsDeployment
{
    use AsAction;

    public function handle(Node $node): void
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
}
