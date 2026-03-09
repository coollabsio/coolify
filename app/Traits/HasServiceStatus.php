<?php

namespace App\Traits;

use App\Enums\ProcessStatus;
use App\Services\ContainerStatusAggregator;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

use function data_get;
use function collect;
use function str;

trait HasServiceStatus
{
    public function isRunning()
    {
        return (bool) str($this->status)->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->contains('exited');
    }

    public function isStarting(): bool
    {
        try {
            $activity = Activity::where('properties->type_uuid', $this->uuid)->latest()->first();
            $status = data_get($activity, 'properties.status');

            return $status === ProcessStatus::QUEUED->value || $status === ProcessStatus::IN_PROGRESS->value;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Calculate the service's aggregate status from its applications and databases.
     *
     * @return string The aggregate status in format "status:health" or "status:health:excluded"
     */
    public function getStatusAttribute()
    {
        if ($this->isStarting()) {
            return 'starting:unhealthy';
        }

        $applications = $this->applications;
        $databases = $this->databases;

        [$complexStatus, $complexHealth, $hasNonExcluded] = $this->aggregateResourceStatuses(
            $applications,
            $databases,
            excludedOnly: false
        );

        // If all services are excluded from status checks, calculate status from excluded containers
        if (!$hasNonExcluded && ($complexStatus === null && $complexHealth === null)) {
            [$excludedStatus, $excludedHealth] = $this->aggregateResourceStatuses(
                $applications,
                $databases,
                excludedOnly: true
            );

            if ($excludedStatus && $excludedHealth) {
                return "{$excludedStatus}:{$excludedHealth}:excluded";
            }

            if ($excludedStatus === null && $excludedHealth === null) {
                return 'unknown:unknown:excluded';
            }

            return 'exited';
        }

        if ($complexHealth === null || $complexHealth === '') {
            return $complexStatus;
        }

        return "{$complexStatus}:{$complexHealth}";
    }

    /**
     * Aggregate status and health from collections of applications and databases.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $applications
     * @param  \Illuminate\Database\Eloquent\Collection  $databases
     * @param  bool  $excludedOnly
     * @return array
     */
    private function aggregateResourceStatuses($applications, $databases, bool $excludedOnly = false): array
    {
        $hasNonExcluded = false;
        $statusStrings = collect();

        $resources = $applications->concat($databases);

        foreach ($resources as $resource) {
            $isExcluded = $resource->exclude_from_status || str($resource->status)->contains(':excluded');

            if ($excludedOnly && !$isExcluded) {
                continue;
            }
            if (!$excludedOnly && $isExcluded) {
                continue;
            }

            if (!$excludedOnly) {
                $hasNonExcluded = true;
            }

            $status = str($resource->status)->before(':excluded')->toString();
            $statusStrings->push($status);
        }

        if ($statusStrings->isEmpty()) {
            return $excludedOnly ? [null, null] : [null, null, $hasNonExcluded];
        }

        $aggregator = new ContainerStatusAggregator;
        $aggregatedStatus = $aggregator->aggregateFromStrings($statusStrings);

        $parts = explode(':', $aggregatedStatus);
        $status = $parts[0] ?? null;
        $health = $parts[1] ?? null;

        if ($excludedOnly) {
            return [$status, $health];
        }

        return [$status, $health, $hasNonExcluded];
    }
}
