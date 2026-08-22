<?php

namespace App\Support\V5;

use App\Models\V5\Application as V5Application;
use App\Models\V5\ResourceConnection;
use Illuminate\Support\Collection;

/**
 * Single source of truth for the resource connection payloads served by the
 * dashboard Inertia props and the connection endpoints — the wire format is
 * consumed by resources/js/v5/types.ts and must stay stable.
 */
class ResourceConnectionSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(ResourceConnection $connection): array
    {
        $applications = $this->applicationsById($connection);
        $resourceOneUuid = $applications->get($connection->resource_one_id)?->uuid;
        $resourceTwoUuid = $applications->get($connection->resource_two_id)?->uuid;
        $applicationsById = $applications;

        return [
            'id' => $connection->uuid,
            'applicationIds' => array_values(array_filter([
                $resourceOneUuid,
                $resourceTwoUuid,
            ])),
            'fromApplicationId' => $resourceOneUuid,
            'toApplicationId' => $resourceTwoUuid,
            'portsByDirection' => $connection->rules
                ->groupBy(function ($rule) use ($applicationsById): string {
                    $sourceUuid = $applicationsById->get($rule->source_resource_id)?->uuid;
                    $targetUuid = $applicationsById->get($rule->target_resource_id)?->uuid;

                    return "{$sourceUuid}->{$targetUuid}";
                })
                ->filter(fn (Collection $rules, string $direction): bool => ! str_starts_with($direction, '->') && ! str_ends_with($direction, '->'))
                ->map(fn (Collection $rules) => $rules
                    ->sortBy('port')
                    ->pluck('port')
                    ->map(fn ($port) => (string) $port)
                    ->values()
                    ->all())
                ->all(),
        ];
    }

    /**
     * @return Collection<string, V5Application>
     */
    public function applicationsByUuid(ResourceConnection $connection): Collection
    {
        return $this->applicationsById($connection)->keyBy('uuid');
    }

    /**
     * @return Collection<int, V5Application>
     */
    public function applicationsById(ResourceConnection $connection): Collection
    {
        return V5Application::query()
            ->whereIn('id', [
                (int) $connection->resource_one_id,
                (int) $connection->resource_two_id,
            ])
            ->get()
            ->keyBy('id');
    }
}
