<?php

namespace App\Mcp\Concerns;

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait ResolvesResource
{
    /**
     * Resource types that own env vars, storages, tags, and container logs.
     *
     * @var array<int, string>
     */
    protected array $primaryResourceTypes = [
        'application',
        'database',
        'service',
    ];

    /**
     * @var array<int, string>
     */
    protected array $logResourceTypes = [
        'application',
        'database',
        'service',
        'service_application',
        'service_database',
    ];

    /**
     * @var array<int, string>
     */
    protected array $scheduledTaskResourceTypes = [
        'application',
        'service',
    ];

    /**
     * Resolve a team-scoped primary resource (application, database, service).
     *
     * Always enforces team ownership — never returns a resource from another team.
     */
    protected function resolveTeamResource(int $teamId, string $resourceType, string $uuid): ?Model
    {
        return match ($resourceType) {
            'application' => Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first(),
            'database' => queryDatabaseByUuidWithinTeam($uuid, (string) $teamId),
            'service' => Service::whereRelation('environment.project.team', 'id', $teamId)
                ->where('uuid', $uuid)
                ->first(),
            default => null,
        };
    }

    /**
     * Resolve a team-scoped resource for log tools (includes service children).
     */
    protected function resolveTeamLogResource(int $teamId, string $resourceType, string $uuid, ?string $parentUuid = null): ?Model
    {
        if (in_array($resourceType, $this->primaryResourceTypes, true)) {
            return $this->resolveTeamResource($teamId, $resourceType, $uuid);
        }

        if ($resourceType === 'service_application') {
            $query = ServiceApplication::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid);
            if (is_string($parentUuid) && $parentUuid !== '') {
                $query->whereHas('service', fn (Builder $q) => $q->where('uuid', $parentUuid));
            }

            return $query->first();
        }

        if ($resourceType === 'service_database') {
            $query = ServiceDatabase::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid);
            if (is_string($parentUuid) && $parentUuid !== '') {
                $query->whereHas('service', fn (Builder $q) => $q->where('uuid', $parentUuid));
            }

            return $query->first();
        }

        return null;
    }

    protected function isValidResourceType(string $type, array $allowed): bool
    {
        return in_array($type, $allowed, true);
    }

    /**
     * MCP log line cap (stricter than the REST API max of 10000).
     */
    protected function normalizeMcpLogLines(mixed $lines): int
    {
        return normalizeLogLines($lines, default: 100, max: 500);
    }
}
