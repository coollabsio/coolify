<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Environment;
use App\Models\ResourceLink;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the server canvas: one zone per server with every resource of the team on it,
 * in the same shape as the environment canvas. Cards keep the positions and links saved on their environment canvas;
 * admins change them through the environment canvas endpoints of each card.
 */
class ServerCanvas
{
    public function __construct(private EnvironmentCanvas $canvas) {}

    /**
     * @param  Collection<int, Server>  $servers  servers of the team to show
     * @return array{title: string, title_link: ?string, can_edit: bool, layout: 'server', zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, positions: array<string, array{x: int, y: int}>}
     */
    public function payload(int $teamId, Collection $servers, ?Server $single, bool $canReadSensitive, bool $canEdit = false): array
    {
        $environments = Environment::query()
            ->whereHas('project', fn ($query) => $query->where('team_id', $teamId))
            ->get(['id', 'canvas_positions']);
        $resources = $this->loadResources($environments->pluck('id'));
        $links = ResourceLink::query()->whereIn('environment_id', $environments->pluck('id'))->orderBy('id')->get();
        $graph = $this->canvas->graph($resources, $links, $canReadSensitive);

        $serverUuids = $servers->pluck('uuid')->all();
        $context = $resources->map(fn (Model $resource) => [
            'context' => $resource->environment->project->name.' / '.$resource->environment->name,
            'context_link' => route('project.resource.index', ['project_uuid' => $resource->environment->project->uuid, 'environment_uuid' => $resource->environment->uuid]),
            // The canvas filters resources by project and environment.
            'project' => ['uuid' => $resource->environment->project->uuid, 'name' => $resource->environment->project->name],
            'environment' => ['uuid' => $resource->environment->uuid, 'name' => $resource->environment->name],
        ]);
        $nodes = collect($graph['nodes'])
            ->filter(fn (array $node) => array_intersect([$node['zone'], ...$node['extra_zones']], $serverUuids) !== [])
            ->map(function (array $node) use ($serverUuids, $context) {
                // A resource that also runs on a shown server (additional server) sits in that server's zone.
                if (! in_array($node['zone'], $serverUuids, true)) {
                    $node['zone'] = collect($node['extra_zones'])->first(fn ($uuid) => in_array($uuid, $serverUuids, true));
                }

                return [...$node, ...$context->get($node['id'])];
            })
            ->values();
        $shown = $nodes->pluck('id')->flip();

        return [
            'title' => $single ? $single->name : 'Servers',
            'title_link' => $single ? route('server.show', ['server_uuid' => $single->uuid]) : null,
            // Unmanaged containers need a command on the server, so the canvas loads them after it renders.
            'containers_endpoint' => $single ? '/servers/'.$single->uuid.'/canvas/containers' : null,
            'can_edit' => $canEdit,
            // Tells the canvas to group cards by project / environment and place linked cards side by side.
            'layout' => 'server',
            'zones' => $servers->map(fn (Server $server) => $this->canvas->zone($server, $canReadSensitive))->values()->all(),
            'nodes' => $nodes->all(),
            'links' => collect($graph['links'])->filter(fn (array $link) => $shown->has($link['source']) && $shown->has($link['target']))->values()->all(),
            // Card positions saved on the environment canvases; the canvas keeps their arrangement per project / environment group.
            'positions' => $this->canvas->filterPositions(
                $environments->reduce(fn (array $positions, Environment $environment) => [...$positions, ...($environment->canvas_positions ?? [])], []),
                $nodes->pluck('id')->all(),
            ),
        ];
    }

    /**
     * Read-only cards for the Docker containers on the server that Coolify does not manage.
     * Only the one-server canvas loads them, in a separate request, because this runs a command on the server.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unmanagedContainerNodes(Server $server): Collection
    {
        try {
            $containers = $server->loadUnmanagedContainers();
        } catch (\Throwable) {
            return collect();
        }

        return $containers
            ->filter(fn ($container) => filled(data_get($container, 'ID')))
            ->map(function ($container) use ($server) {
                $health = str((string) data_get($container, 'Status'))->match('/\((healthy|unhealthy)\)/')->value();

                return [
                    'id' => 'container:'.data_get($container, 'ID'),
                    'type' => 'container',
                    'name' => (string) data_get($container, 'Names'),
                    'status' => collect([(string) data_get($container, 'State', 'unknown'), $health])->filter()->implode(':'),
                    'zone' => $server->uuid,
                    'extra_zones' => [],
                    'urls' => [],
                    'subtitle' => (string) data_get($container, 'Image'),
                    'children' => [],
                    'volumes' => [],
                    'link' => route('server.resources', ['server_uuid' => $server->uuid]),
                    'context' => 'Unmanaged containers',
                    'context_link' => route('server.resources', ['server_uuid' => $server->uuid]),
                ];
            })
            ->sortBy('name', SORT_NATURAL)
            ->values();
    }

    /**
     * Top-level resources of the given environments, keyed by uuid, with the relations the canvas reads.
     *
     * @param  Collection<int, int>  $environmentIds
     * @return Collection<string, Model>
     */
    public function loadResources(Collection $environmentIds): Collection
    {
        $applications = Application::query()->whereIn('environment_id', $environmentIds)
            ->with(['environment.project', 'destination.server.settings', 'additional_servers.settings', 'persistentStorages'])
            ->get();
        $services = Service::query()->whereIn('environment_id', $environmentIds)
            ->with([
                'environment.project',
                'server.settings',
                'destination.server.settings',
                'applications.persistentStorages',
                'databases.persistentStorages',
            ])
            ->get();
        $databases = collect(EnvironmentCanvas::DATABASE_RELATIONS)
            ->flatMap(fn (string $relation) => (new Environment)->{$relation}()->getRelated()->newQuery()
                ->whereIn('environment_id', $environmentIds)
                ->with(['environment.project', 'destination.server.settings', 'persistentStorages'])
                ->get());

        return collect()->concat($applications)->concat($services)->concat($databases)->keyBy('uuid');
    }
}
