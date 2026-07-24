<?php

namespace App\Support;

use App\Models\Environment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Maps Coolify resources (applications, services, standalone databases) into the
 * flat "node" view-model consumed by the Railway-style UI.
 *
 * Coolify has no single accessor that returns every resource in an environment,
 * so this centralises the `applications + services + databases()` enumeration and
 * the icon/status/subtitle derivation used by both the projects list and canvas.
 */
final class RailwayResourceMapper
{
    /**
     * Every deployable resource in an environment, sorted by name.
     *
     * @return Collection<int, Model>
     */
    public static function resourcesFor(Environment $environment): Collection
    {
        return $environment->applications
            ->concat($environment->services)
            ->concat($environment->databases())
            ->sortBy('name')
            ->values();
    }

    /**
     * True when the resource's container status reports as running (green dot).
     */
    public static function isOnline(Model $resource): bool
    {
        $status = strtolower((string) ($resource->status ?? ''));

        return str_starts_with($status, 'running');
    }

    /**
     * application | service | database
     */
    public static function kind(Model $resource): string
    {
        return match (class_basename($resource)) {
            'Application' => 'application',
            'Service' => 'service',
            default => 'database',
        };
    }

    /**
     * Icon glyph key understood by <x-railway.resource-icon>.
     */
    public static function iconType(Model $resource): string
    {
        return match (self::kind($resource)) {
            'service' => 'service',
            'database' => 'database',
            default => filled($resource->docker_registry_image_name ?? null) ? 'terminal' : 'github',
        };
    }

    /**
     * The muted second line under the node title: public host, else internal id.
     */
    public static function subtitle(Model $resource): ?string
    {
        $fqdn = $resource->fqdn ?? null;

        if (filled($fqdn)) {
            $first = explode(',', (string) $fqdn)[0];

            return preg_replace('#^https?://#', '', trim($first)) ?: null;
        }

        return $resource->uuid ?? null;
    }

    /**
     * The classic Coolify configuration route for this resource (deep-link fallback).
     */
    public static function href(Model $resource, string $projectUuid, string $environmentUuid): string
    {
        return match (self::kind($resource)) {
            'application' => route('project.application.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'application_uuid' => $resource->uuid,
            ]),
            'service' => route('project.service.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'service_uuid' => $resource->uuid,
            ]),
            default => route('project.database.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'database_uuid' => $resource->uuid,
            ]),
        };
    }

    /**
     * Full node view-model for the canvas.
     *
     * @return array<string, mixed>
     */
    public static function toNode(Model $resource, string $projectUuid, string $environmentUuid): array
    {
        return [
            'id' => $resource->uuid,
            'name' => $resource->name,
            'subtitle' => self::subtitle($resource),
            'status' => (string) ($resource->status ?? ''),
            'online' => self::isOnline($resource),
            'kind' => self::kind($resource),
            'icon' => self::iconType($resource),
            'href' => self::href($resource, $projectUuid, $environmentUuid),
        ];
    }
}
