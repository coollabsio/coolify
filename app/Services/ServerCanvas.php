<?php

namespace App\Services;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ResourceLink;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the server canvas: one zone per server with every resource of the team on it,
 * in the same shape as the environment canvas. Cards and project / environment groups use the positions saved on
 * their environment canvas (node.saved); admins move whole groups and single cards here, which saves the group's cards on that environment
 * canvas, and change links through the environment canvas endpoints of each card.
 */
class ServerCanvas
{
    public function __construct(private EnvironmentCanvas $canvas) {}

    /**
     * @param  Collection<int, Server>  $servers  servers of the team to show
     * @return array{title: string, title_link: ?string, can_edit: bool, can_move: false, can_move_groups: bool, can_move_zones: bool, layout: 'server', zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, positions: array{}}
     */
    public function payload(int $teamId, Collection $servers, ?Server $single, bool $canReadSensitive, bool $canEdit = false): array
    {
        $environments = Environment::query()
            ->whereHas('project', fn ($query) => $query->where('team_id', $teamId))
            ->with('project')
            ->get(['id', 'uuid', 'name', 'project_id']);
        $environmentIds = $environments->pluck('id');
        $resources = $this->loadResources($environmentIds);
        $links = ResourceLink::query()->whereIn('environment_id', $environmentIds)->orderBy('id')->get();
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
        // Every-server canvases: environments without resources show as placeholder cards next to the other environments of their project.
        $used = $resources->pluck('environment_id')->flip();
        $zoneOf = $this->canvas->projectZones($nodes, $serverUuids[0] ?? EnvironmentCanvas::EMPTY_ZONE);
        $empty = $single ? collect() : $environments
            ->reject(fn (Environment $environment) => $used->has($environment->id))
            ->map(fn (Environment $environment) => $this->canvas->emptyNode($environment->project, $environment, $zoneOf($environment->project), $environment->project->name.' / '.$environment->name, $canEdit))
            ->values();
        // The canvas draws a zone around its cards, so a server without cards gets a placeholder card.
        $filled = $nodes->concat($empty)->pluck('zone')->flip();
        $bare = $servers->reject(fn (Server $server) => $filled->has($server->uuid))->map(fn (Server $server) => [
            'id' => 'empty-server:'.$server->uuid,
            'type' => 'empty',
            'name' => 'No resources yet',
            'subtitle' => 'Open to see this server',
            'status' => null,
            'zone' => $server->uuid,
            'extra_zones' => [],
            'urls' => [],
            'children' => [],
            'link' => route('server.show', ['server_uuid' => $server->uuid]),
            'context' => null,
            'project' => null,
            'environment' => null,
        ])->values();

        return [
            'title' => $single ? $single->name : 'Servers',
            'title_link' => $single ? route('server.show', ['server_uuid' => $single->uuid]) : null,
            // Unmanaged containers need a command on the server, so the canvas loads them after it renders.
            'containers_endpoint' => $single ? '/servers/'.$single->uuid.'/canvas/containers' : null,
            'can_edit' => $canEdit,
            'can_move' => false,
            // Admins drag the project / environment group boxes; the canvas saves the moved cards on their environment canvas.
            'can_move_groups' => $canEdit,
            // Admins move the server zones of the every-server canvases; each server saves its place (zone.position).
            'can_move_zones' => $canEdit && ! $single,
            // Tells the canvas to group cards by project / environment and place linked cards side by side.
            'layout' => 'server',
            'zones' => $servers->map(fn (Server $server) => [
                ...$this->canvas->zone($server, $canReadSensitive),
                'details' => $this->details($server),
                'position' => $single ? null : $this->canvas->zonePosition($server->canvas_position),
            ])
                ->when($empty->contains('zone', EnvironmentCanvas::EMPTY_ZONE), fn (Collection $zones) => $zones->push($this->canvas->emptyZone()))
                ->values()->all(),
            'nodes' => $nodes->concat($empty)->concat($bare)->all(),
            'links' => collect($graph['links'])->filter(fn (array $link) => $shown->has($link['source']) && $shown->has($link['target']))->values()->all(),
            'positions' => [],
        ];
    }

    /**
     * Host facts and proxy of a server for its zone header. Live usage loads after the canvas renders
     * (usage_endpoint), because it runs a command on the server.
     *
     * @return array{os: ?string, arch: ?string, cpus: ?int, memory_bytes: ?int, uptime_since: ?string, proxy: ?array{type: string, status: ?string}, usage_endpoint: ?string}
     */
    public function details(Server $server): array
    {
        $meta = $server->server_metadata ?? [];
        $proxyType = $server->proxyType();
        $live = $server->settings?->is_reachable && $server->settings?->is_metrics_enabled;

        return [
            'os' => $meta['os'] ?? null,
            'arch' => $meta['arch'] ?? null,
            'cpus' => isset($meta['cpus']) ? (int) $meta['cpus'] : null,
            'memory_bytes' => isset($meta['memory_bytes']) ? (int) $meta['memory_bytes'] : null,
            'uptime_since' => $meta['uptime_since'] ?? null,
            'proxy' => $proxyType && $proxyType !== 'NONE' ? ['type' => $proxyType, 'status' => data_get($server->proxy, 'status')] : null,
            'usage_endpoint' => $live ? '/servers/'.$server->uuid.'/canvas/usage' : null,
        ];
    }

    /**
     * Latest CPU, memory and root disk usage in percent from Sentinel, cached briefly so that
     * canvas refreshes do not run a command on the server each time. Null values: no data.
     *
     * @return array{cpu: ?float, memory: ?float, disk: ?float}
     */
    public function usage(Server $server): array
    {
        return Cache::remember("server-canvas-usage:{$server->id}", 30, function () use ($server) {
            $latest = function (callable $read): ?float {
                try {
                    $value = data_get(last($read() ?: [null]), 1);

                    return is_numeric($value) ? round((float) $value, 1) : null;
                } catch (\Throwable) {
                    return null;
                }
            };

            $server->prefetchMetrics(['cpu', 'memory', 'disk'], 1);

            return [
                'cpu' => $latest(fn () => $server->getCpuMetrics(1)),
                'memory' => $latest(fn () => $server->getMemoryMetrics(1)),
                'disk' => $latest(fn () => $server->getDiskMetrics(1)),
            ];
        });
    }

    /**
     * Dashboard canvas: the server canvas of the team, on each application card its
     * running or queued deployment (the oldest first, as the deployment queue runs them),
     * and the list of active and recent deployments.
     *
     * @param  Collection<int, Server>  $servers  servers of the team to show
     * @return array<string, mixed>
     */
    public function dashboardPayload(int $teamId, Collection $servers, bool $canReadSensitive, bool $canEdit = false): array
    {
        $payload = $this->payload($teamId, $servers, null, $canReadSensitive, $canEdit);

        $columns = ['deployment_uuid', 'application_id', 'application_name', 'status', 'deployment_url', 'pull_request_id', 'server_name', 'created_at', 'finished_at'];
        $query = fn () => ApplicationDeploymentQueue::query()->whereIn('server_id', $servers->pluck('id'));
        $activeStatuses = [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value];
        $active = $query()->whereIn('status', $activeStatuses)->orderBy('status')->orderBy('id')->get($columns);
        $recent = $query()->whereNotIn('status', $activeStatuses)->orderByDesc('id')->limit(8)->get($columns);
        $applicationUuids = Application::query()
            ->whereIn('id', $active->concat($recent)->pluck('application_id')->unique())
            ->pluck('uuid', 'id');
        $uuidOf = fn (ApplicationDeploymentQueue $deployment) => $applicationUuids->get((int) $deployment->application_id);
        $summary = fn (ApplicationDeploymentQueue $deployment) => [
            'status' => $deployment->status,
            'link' => $deployment->deployment_url,
            'pull_request_id' => (int) $deployment->pull_request_id ?: null,
        ];
        $byUuid = $active
            ->filter($uuidOf)
            ->unique('application_id')
            ->mapWithKeys(fn (ApplicationDeploymentQueue $deployment) => [$uuidOf($deployment) => $summary($deployment)]);

        return [
            ...$payload,
            'title' => 'Dashboard',
            'title_link' => route('dashboard'),
            'nodes' => collect($payload['nodes'])
                ->map(fn (array $node) => $node['type'] === 'application' && $byUuid->has($node['id'])
                    ? [...$node, 'deployment' => $byUuid->get($node['id'])]
                    : $node)
                ->all(),
            // The deployment panel: running and queued deployments first, then the latest finished ones.
            'deployments' => $active->concat($recent)
                ->map(fn (ApplicationDeploymentQueue $deployment) => [
                    'id' => $deployment->deployment_uuid,
                    'application' => $uuidOf($deployment),
                    'name' => $deployment->application_name,
                    'server' => $deployment->server_name,
                    'created_at' => $deployment->created_at?->toIso8601String(),
                    'finished_at' => $deployment->finished_at?->toIso8601String(),
                    ...$summary($deployment),
                ])
                ->values()
                ->all(),
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
            ->with(['environment.project', 'destination.server.settings', 'additional_servers.settings'])
            ->get();
        $services = Service::query()->whereIn('environment_id', $environmentIds)
            ->with([
                'environment.project',
                'server.settings',
                'destination.server.settings',
                'applications',
                'databases',
            ])
            ->get();
        $databases = collect(EnvironmentCanvas::DATABASE_RELATIONS)
            ->flatMap(fn (string $relation) => (new Environment)->{$relation}()->getRelated()->newQuery()
                ->whereIn('environment_id', $environmentIds)
                ->with(['environment.project', 'destination.server.settings'])
                ->get());

        return collect()->concat($applications)->concat($services)->concat($databases)->keyBy('uuid');
    }
}
