<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ResourceLink;
use App\Models\Server;
use App\Models\Service;
use App\Support\ResourceStartActivity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Builds the environment canvas graph: server zones, resource cards, manual links and saved positions.
 *
 * Cards hold summary fields only. Never add environment variables, passwords, keys or connection URLs.
 */
class EnvironmentCanvas
{
    public const MAX_COORDINATE = 100000;

    /** Zone id of the placeholder cards of environments without resources. */
    public const EMPTY_ZONE = 'empty';

    /**
     * Environment relations that hold standalone databases, keyed by relation name.
     */
    public const DATABASE_RELATIONS = [
        'postgresqls',
        'redis',
        'mongodbs',
        'mysqls',
        'mariadbs',
        'keydbs',
        'dragonflies',
        'clickhouses',
        'sqlites',
    ];

    private const DATABASE_ENGINE_NAMES = [
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'mongodb' => 'MongoDB',
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'keydb' => 'KeyDB',
        'dragonfly' => 'Dragonfly',
        'clickhouse' => 'ClickHouse',
        'sqlite' => 'SQLite',
    ];

    private const BUILD_PACK_NAMES = [
        'nixpacks' => 'Nixpacks',
        'railpack' => 'Railpack',
        'static' => 'Static',
        'dockerfile' => 'Dockerfile',
        'dockercompose' => 'Docker Compose',
        'dockerimage' => 'Docker Image',
    ];

    /**
     * The canvas payload of an environment.
     *
     * @return array{project: array{uuid: string, name: string, link: string}, environment: array{uuid: string, name: string, link: string}, can_edit: bool, zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array{id: string, source: string, target: string, label: ?string}>, positions: array<string, array{x: int, y: int}>}
     */
    public function payload(Environment $environment, bool $canReadSensitive, bool $canEdit): array
    {
        $environment->loadMissing('project');
        $resources = $this->loadResources($environment);
        $graph = $this->graph($resources, $environment->resourceLinks()->orderBy('id')->get(), $canReadSensitive);

        return [
            'project' => [
                'uuid' => $environment->project->uuid,
                'name' => $environment->project->name,
                'link' => route('project.show', ['project_uuid' => $environment->project->uuid]),
            ],
            'environment' => [
                'uuid' => $environment->uuid,
                'name' => $environment->name,
                'link' => route('project.resource.index', ['project_uuid' => $environment->project->uuid, 'environment_uuid' => $environment->uuid]),
            ],
            'can_edit' => $canEdit,
            ...$graph,
            'positions' => $this->filterPositions($environment->canvas_positions ?? [], $resources->keys()->all()),
        ];
    }

    /**
     * Zones (servers), cards and links of the given resources. Links with an end outside the resources are left out.
     *
     * @param  Collection<string, Model>  $resources  keyed by uuid
     * @param  Collection<int, ResourceLink>  $links
     * @return array{zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array{id: string, source: string, target: string, label: ?string}>}
     */
    public function graph(Collection $resources, Collection $links, bool $canReadSensitive): array
    {
        $startingServiceUuids = $this->startingServiceUuids($resources->filter(fn (Model $resource) => $resource instanceof Service));

        $servers = collect();
        $nodes = $resources->map(function (Model $resource) use (&$servers, $startingServiceUuids) {
            $node = match (true) {
                $resource instanceof Application => $this->applicationNode($resource),
                $resource instanceof Service => $this->serviceNode($resource, in_array($resource->uuid, $startingServiceUuids, true)),
                default => $this->databaseNode($resource),
            };
            foreach ($node['servers'] as $server) {
                $servers->put($server->id, $server);
            }
            unset($node['servers']);
            // Other canvases keep the arrangement saved on the environment canvas inside each environment group.
            $node['saved'] = $this->filterPositions($resource->environment?->canvas_positions ?? [], [$resource->uuid])[$resource->uuid] ?? null;

            return $node;
        })->values();

        $uuidByMorphKey = $resources->mapWithKeys(fn (Model $resource) => [$this->morphKey($resource->getMorphClass(), $resource->getKey()) => $resource->uuid]);

        $links = $links
            ->map(fn (ResourceLink $link) => [
                'link' => $link,
                'source' => $uuidByMorphKey->get($this->morphKey($link->source_type, $link->source_id)),
                'target' => $uuidByMorphKey->get($this->morphKey($link->target_type, $link->target_id)),
            ])
            ->filter(fn (array $link) => $link['source'] !== null && $link['target'] !== null)
            ->map(fn (array $link) => $this->linkPayload($link['link'], $link['source'], $link['target']))
            ->values();

        return [
            'zones' => $servers->values()->map(fn (Server $server) => $this->zone($server, $canReadSensitive))->all(),
            'nodes' => $nodes->all(),
            'links' => $links->all(),
        ];
    }

    /**
     * A saved zone position (projects and servers canvas), or null when the zone is auto placed.
     *
     * @return array{x: int, y: int}|null
     */
    public function zonePosition(mixed $saved): ?array
    {
        return is_array($saved) && is_numeric($saved['x'] ?? null) && is_numeric($saved['y'] ?? null)
            ? ['x' => (int) $saved['x'], 'y' => (int) $saved['y']]
            : null;
    }

    /**
     * @return array{id: string, name: string, ip: ?string, is_reachable: bool, is_usable: bool, link: string}
     */
    public function zone(Server $server, bool $canReadSensitive): array
    {
        return [
            'id' => $server->uuid,
            'name' => $server->name,
            'ip' => $canReadSensitive ? $server->ip : null,
            'is_reachable' => (bool) $server->settings?->is_reachable,
            'is_usable' => (bool) $server->settings?->is_usable,
            'link' => route('server.show', ['server_uuid' => $server->uuid]),
        ];
    }

    /**
     * Zone of the server, project and dashboard canvases for environments without resources (they have no server).
     *
     * @return array{id: string, name: string, icon: string}
     */
    public function emptyZone(): array
    {
        return ['id' => self::EMPTY_ZONE, 'name' => 'Empty environments', 'icon' => 'service'];
    }

    /**
     * Zone for the placeholder cards of a project: the zone with most of the project's cards, or `$fallback`
     * when the project has no cards.
     *
     * @param  Collection<int, array<string, mixed>>  $nodes  cards of the canvas
     * @return callable(Project): string
     */
    public function projectZones(Collection $nodes, string $fallback): callable
    {
        $zones = $nodes
            ->filter(fn (array $node) => filled($node['zone']))
            ->groupBy(fn (array $node) => data_get($node, 'project.uuid'))
            ->map(fn (Collection $cards) => $cards->countBy('zone')->sortDesc()->keys()->first());

        return fn (Project $project) => $zones->get($project->uuid, $fallback);
    }

    /**
     * Placeholder card of an environment without resources, so the grouped canvases still show it.
     * Without an environment, it stands for a project without environments.
     * `$canAdd`: the card opens the deploy canvas for the environment instead of the environment page.
     *
     * @return array<string, mixed>
     */
    public function emptyNode(Project $project, ?Environment $environment, string $zone, string $context, bool $canAdd = false): array
    {
        $page = $environment
            ? route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid])
            : route('project.show', ['project_uuid' => $project->uuid]);
        $add = $canAdd && $environment;

        return [
            'id' => 'empty:'.($environment?->uuid ?? $project->uuid),
            'type' => 'empty',
            'name' => $environment ? 'No resources yet' : 'No environments yet',
            'subtitle' => $add ? 'Click to add a resource' : 'Open to see this '.($environment ? 'environment' : 'project'),
            'status' => null,
            'zone' => $zone,
            'extra_zones' => [],
            'urls' => [],
            'children' => [],
            'link' => $add ? route('deploy-canvas', ['project' => $project->uuid, 'environment' => $environment->uuid]) : $page,
            'context' => $context,
            'context_link' => $page,
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => $environment ? ['uuid' => $environment->uuid, 'name' => $environment->name] : null,
        ];
    }

    /**
     * Top-level resources of the environment (applications, services, standalone databases), keyed by uuid,
     * with the relations the canvas reads.
     *
     * @return Collection<string, Model>
     */
    public function loadResources(Environment $environment): Collection
    {
        $project = $environment->project;
        $environment->setRelation('project', $project);
        $applications = $environment->applications()
            ->with(['destination.server.settings', 'additional_servers.settings'])
            ->get();
        $services = $environment->services()
            ->with([
                'server.settings',
                'destination.server.settings',
                'applications',
                'databases',
            ])
            ->get();
        $databases = collect(self::DATABASE_RELATIONS)
            ->flatMap(fn (string $relation) => $environment->{$relation}()
                ->with(['destination.server.settings'])
                ->get());

        return collect()
            ->concat($applications)
            ->concat($services)
            ->concat($databases)
            ->each(fn (Model $resource) => $resource->setRelation('environment', $environment))
            ->keyBy('uuid');
    }

    /**
     * Morph type and id of every top-level resource of the environment, keyed by uuid. Reads ids only.
     *
     * @return Collection<string, array{type: string, id: int}>
     */
    public function resourceIndex(Environment $environment): Collection
    {
        return collect(['applications', 'services', ...self::DATABASE_RELATIONS])
            ->flatMap(function (string $relation) use ($environment) {
                $query = $environment->{$relation}();
                $type = $query->getRelated()->getMorphClass();

                return $query->toBase()->get(['id', 'uuid'])
                    ->map(fn (object $row) => ['uuid' => $row->uuid, 'type' => $type, 'id' => (int) $row->id]);
            })
            ->keyBy('uuid')
            ->map(fn (array $row) => ['type' => $row['type'], 'id' => $row['id']]);
    }

    /**
     * Keep only positions of the given node uuids, as integer coordinates.
     *
     * @param  array<string, mixed>  $positions
     * @param  array<int, string>  $nodeUuids
     * @return array<string, array{x: int, y: int}>
     */
    public function filterPositions(array $positions, array $nodeUuids): array
    {
        return collect($positions)
            ->only($nodeUuids)
            ->filter(fn ($position) => is_array($position) && is_numeric($position['x'] ?? null) && is_numeric($position['y'] ?? null))
            ->map(fn (array $position) => ['x' => (int) $position['x'], 'y' => (int) $position['y']])
            ->all();
    }

    /**
     * @return array{id: string, source: string, target: string, label: ?string}
     */
    public function linkPayload(ResourceLink $link, string $sourceUuid, string $targetUuid): array
    {
        return [
            'id' => $link->uuid,
            'source' => $sourceUuid,
            'target' => $targetUuid,
            'label' => $link->label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationNode(Application $application): array
    {
        $server = $application->destination?->server;
        $extraServers = $application->additional_servers->filter()->values();
        $buildPack = $application->build_pack;
        $detail = $buildPack === 'dockerimage'
            ? collect([$application->docker_registry_image_name, $application->docker_registry_image_tag])->filter()->implode(':')
            : $application->git_branch;

        return [
            'id' => $application->uuid,
            'type' => 'application',
            'name' => $application->name,
            'status' => (string) $application->status,
            'zone' => $server?->uuid,
            'extra_zones' => $extraServers->pluck('uuid')->reject(fn ($uuid) => $uuid === $server?->uuid)->unique()->values()->all(),
            'urls' => $this->splitUrls($application->fqdn),
            'kind' => $buildPack,
            'subtitle' => $this->subtitle([self::BUILD_PACK_NAMES[$buildPack] ?? str($buildPack)->headline()->value(), $detail]),
            'children' => [],
            'link' => $application->link(),
            'logo' => resource_logo($application),
            'servers' => collect([$server])->concat($extraServers)->filter()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceNode(Service $service, bool $isStarting): array
    {
        $server = $service->server ?? $service->destination?->server;
        $children = $service->applications
            ->map(fn ($child) => ['name' => $child->name, 'type' => 'application', 'status' => (string) $child->status])
            ->concat($service->databases->map(fn ($child) => ['name' => $child->name, 'type' => 'database', 'status' => (string) $child->status]))
            ->values();
        $containerCount = $children->count();

        return [
            'id' => $service->uuid,
            'type' => 'service',
            'name' => $service->name,
            'status' => $isStarting ? 'starting:unhealthy' : (string) $service->containerStatus(),
            'zone' => $server?->uuid,
            'extra_zones' => [],
            'urls' => $service->applications
                ->flatMap(fn ($child) => $this->splitUrls($child->fqdn))
                ->unique()
                ->values()
                ->all(),
            'kind' => null,
            'subtitle' => $this->subtitle(['Service', $containerCount.' '.str('container')->plural($containerCount)]),
            'children' => $children->all(),
            'link' => $service->link(),
            'logo' => resource_logo($service),
            'servers' => collect([$server])->filter()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseNode(Model $database): array
    {
        $server = $database->destination?->server;
        $engine = str($database->type())->after('standalone-')->value();

        return [
            'id' => $database->uuid,
            'type' => 'database',
            'name' => $database->name,
            'status' => (string) $database->status,
            'zone' => $server?->uuid,
            'extra_zones' => [],
            'urls' => [],
            'kind' => $engine,
            'subtitle' => $this->subtitle([self::DATABASE_ENGINE_NAMES[$engine] ?? str($engine)->headline()->value(), $database->image]),
            'children' => [],
            'link' => $database->link(),
            'logo' => resource_logo($database),
            'servers' => collect([$server])->filter()->all(),
        ];
    }

    /**
     * Uuids of services with a queued or running start, read with one query instead of one per service.
     *
     * @param  Collection<string, Service>  $services
     * @return array<int, string>
     */
    private function startingServiceUuids(Collection $services): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $staleAfterSeconds = max(ResourceStartActivity::QUEUED_STALE_AFTER_SECONDS, ResourceStartActivity::IN_PROGRESS_STALE_AFTER_SECONDS);

        /** @var EloquentCollection<int, Activity> $activities */
        $activities = Activity::query()
            ->whereIn('properties->type_uuid', $services->pluck('uuid')->all())
            ->where('updated_at', '>=', now()->subSeconds($staleAfterSeconds))
            ->latest()
            ->orderByDesc('id')
            ->get();

        return $activities
            ->groupBy(fn (Activity $activity) => (string) data_get($activity, 'properties.type_uuid'))
            ->filter(fn (EloquentCollection $group) => ResourceStartActivity::isRunning($group->first()))
            ->keys()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function splitUrls(?string $fqdn): array
    {
        return str((string) $fqdn)->explode(',')
            ->map(fn (string $url) => trim($url))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private function subtitle(array $parts): string
    {
        return collect($parts)->map(fn ($part) => trim((string) $part))->filter()->implode(' · ');
    }

    private function morphKey(string $type, int|string $id): string
    {
        return $type.'|'.$id;
    }
}
