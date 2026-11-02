<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the shared variables canvas of one scope (team, project, environment or server):
 * the shared variables on the left, the resources that can use them on the right, and a link
 * for every resource that references a variable ({{team.KEY}}, ...) in one of its own variables.
 *
 * Links come from the saved resource variables, so a deleted reference removes the link and a
 * reference to a deleted shared variable shows as a missing variable. Values are never sent.
 */
class SharedVariableCanvas
{
    public const SCOPES = ['team', 'project', 'environment', 'server'];

    public const SOURCES_ZONE = 'sources';

    public const CONNECTED_ZONE = 'connected';

    public const RESOURCES_ZONE = 'resources';

    /**
     * Server variables that Coolify creates on every server. Resources get them only through a reference,
     * but every server has them, so the canvas and the overview leave them out to stay readable.
     */
    public const BUILT_IN_SERVER_KEYS = ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'];

    public function __construct(private EnvironmentCanvas $canvas, private ServerCanvas $serverCanvas) {}

    /**
     * @return array{title: string, title_link: string, can_edit: bool, can_move: false, layout: 'connect', scope: string, zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, positions: array{}}
     */
    public function payload(string $scope, Model $owner, bool $canEdit): array
    {
        $hidden = $scope === 'server' ? self::BUILT_IN_SERVER_KEYS : [];
        $variables = $this->variables($owner)->reject(fn (SharedEnvironmentVariable $variable) => in_array($variable->key, $hidden, true))->values();
        $resources = $this->resources($scope, $owner);
        // A reference to a hidden variable is not a reference to a deleted one: leave its link out too.
        $links = collect($this->links($scope, $variables, $resources))
            ->reject(fn (array $link) => in_array($link['key'], $hidden, true))
            ->values()
            ->all();

        $missing = collect($links)
            ->where('missing', true)
            ->unique('source')
            ->map(fn (array $link) => [
                'id' => $link['source'],
                'type' => 'variable',
                'name' => $link['key'],
                'subtitle' => 'Deleted shared variable',
                'reference' => $this->reference($scope, $link['key']),
                'missing' => true,
                'zone' => self::SOURCES_ZONE,
                'extra_zones' => [],
                'status' => null,
            ]);

        $resourceNodes = $this->resourceNodes($resources);

        return [
            'title' => $this->title($scope, $owner),
            'title_link' => $this->pageLink($scope, $owner),
            'can_edit' => $canEdit,
            'can_move' => false,
            'layout' => 'connect',
            'scope' => $scope,
            'zones' => [
                ['id' => self::SOURCES_ZONE, 'name' => str($scope)->headline().' variables', 'icon' => 'variable'],
                // The canvas moves the resources that use a variable into this zone.
                ['id' => self::CONNECTED_ZONE, 'name' => 'Using these variables', 'icon' => 'link'],
                ['id' => self::RESOURCES_ZONE, 'name' => 'Other resources', 'icon' => 'service'],
            ],
            'nodes' => $variables
                ->map(fn (SharedEnvironmentVariable $variable) => [
                    'id' => $this->variableNodeId($variable),
                    'type' => 'variable',
                    'name' => $variable->key,
                    'subtitle' => filled($variable->comment) ? $variable->comment : str($scope)->headline().' variable',
                    'reference' => $this->reference($scope, $variable->key),
                    'missing' => false,
                    'zone' => self::SOURCES_ZONE,
                    'extra_zones' => [],
                    'status' => null,
                ])
                ->concat($missing)
                ->concat($resourceNodes)
                ->values()
                ->all(),
            'links' => $links,
            'positions' => [],
        ];
    }

    /**
     * Resource cards of a connect canvas (shared variables or tags), with the project / environment
     * the canvas groups and filters them by. The canvas moves used ones into the connected zone.
     *
     * @param  Collection<string, Model>  $resources  keyed by uuid
     * @return Collection<int, array<string, mixed>>
     */
    public function resourceNodes(Collection $resources): Collection
    {
        $graph = $this->canvas->graph($resources, collect(), false);

        return collect($graph['nodes'])->map(function (array $node) use ($resources) {
            $environment = $resources->get($node['id'])->environment;

            return [
                ...$node,
                'zone' => self::RESOURCES_ZONE,
                'extra_zones' => [],
                'context' => $environment->project->name.' / '.$environment->name,
                'context_link' => route('project.resource.index', ['project_uuid' => $environment->project->uuid, 'environment_uuid' => $environment->uuid]),
                // The canvas filters resources by project and environment.
                'project' => ['uuid' => $environment->project->uuid, 'name' => $environment->project->name],
                'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            ];
        });
    }

    /**
     * Shared variables of the scope owner, by key.
     *
     * @return Collection<int, SharedEnvironmentVariable>
     */
    public function variables(Model $owner): Collection
    {
        return $owner->environment_variables()->orderBy('key')->get();
    }

    /**
     * Top-level resources that can use the variables of the scope, keyed by uuid.
     *
     * @return Collection<string, Model>
     */
    public function resources(string $scope, Model $owner): Collection
    {
        $environmentIds = match ($scope) {
            'team' => Environment::query()->whereHas('project', fn ($query) => $query->where('team_id', $owner->id))->pluck('id'),
            'project' => $owner->environments()->pluck('id'),
            'environment' => collect([$owner->id]),
            'server' => Environment::query()->whereHas('project', fn ($query) => $query->where('team_id', $owner->team_id))->pluck('id'),
        };
        $resources = $this->serverCanvas->loadResources($environmentIds);

        if ($scope === 'server') {
            // {{server.KEY}} resolves against the main server of the resource.
            $resources = $resources->filter(fn (Model $resource) => $this->mainServer($resource)?->id === $owner->id);
        }

        return $resources->sortBy(fn (Model $resource) => mb_strtolower($resource->name))->keyBy('uuid');
    }

    /**
     * One link per (shared variable, resource) pair where a variable of the resource references the shared variable.
     *
     * @param  Collection<int, SharedEnvironmentVariable>  $variables
     * @param  Collection<string, Model>  $resources
     * @return array<int, array{id: string, source: string, target: string, key: string, keys: array<int, string>, label: ?string, removable: bool, missing: bool}>
     */
    public function links(string $scope, Collection $variables, Collection $resources): array
    {
        $nodeIdByKey = $variables->mapWithKeys(fn (SharedEnvironmentVariable $variable) => [$variable->key => $this->variableNodeId($variable)]);
        $resourceByMorph = $resources->mapWithKeys(fn (Model $resource) => [$resource->getMorphClass().'#'.$resource->getKey() => $resource]);
        $links = [];
        foreach ($this->resourceVariables($resources) as $environmentVariable) {
            $resource = $resourceByMorph->get($environmentVariable->resourceable_type.'#'.$environmentVariable->resourceable_id);
            foreach ($this->referencedKeys($scope, $environmentVariable->value) as $key) {
                $source = $nodeIdByKey->get($key, 'missing:'.$key);
                $id = $source.'|'.$resource->uuid;
                $links[$id] ??= ['id' => $id, 'source' => $source, 'target' => $resource->uuid, 'key' => $key, 'keys' => [], 'label' => null, 'removable' => true, 'missing' => ! $nodeIdByKey->has($key)];
                $links[$id]['keys'][] = $environmentVariable->key;
                // Only a variable that holds nothing but the reference can be removed from the canvas.
                $links[$id]['removable'] = $links[$id]['removable'] && $this->isExactReference($scope, $key, $environmentVariable->value);
            }
        }

        return collect($links)
            ->map(function (array $link) {
                $keys = array_values(array_unique($link['keys']));

                // Label the link when the resource uses another name for the variable.
                return [...$link, 'keys' => $keys, 'label' => $keys === [$link['key']] ? null : implode(', ', $keys)];
            })
            ->values()
            ->all();
    }

    /**
     * Saved variables (not preview copies) of the given resources.
     *
     * @param  Collection<string, Model>  $resources
     * @return Collection<int, EnvironmentVariable>
     */
    public function resourceVariables(Collection $resources): Collection
    {
        if ($resources->isEmpty()) {
            return collect();
        }

        return EnvironmentVariable::query()
            ->where('is_preview', false)
            ->where(function ($query) use ($resources) {
                foreach ($resources->groupBy(fn (Model $resource) => $resource->getMorphClass()) as $type => $group) {
                    $query->orWhere(fn ($query) => $query->where('resourceable_type', $type)->whereIn('resourceable_id', $group->map->getKey()->all()));
                }
            })
            ->orderBy('key')
            ->get();
    }

    /**
     * Keys of the shared variables of the scope that the value references.
     *
     * @return array<int, string>
     */
    public function referencedKeys(string $scope, ?string $value): array
    {
        if (blank($value)) {
            return [];
        }
        preg_match_all('/{{\s*'.preg_quote($scope, '/').'\.(.+?)\s*}}/', $value, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function isExactReference(string $scope, string $key, ?string $value): bool
    {
        return preg_match('/^{{\s*'.preg_quote($scope, '/').'\.'.preg_quote($key, '/').'\s*}}$/', trim((string) $value)) === 1;
    }

    public function reference(string $scope, string $key): string
    {
        return '{{'.$scope.'.'.$key.'}}';
    }

    public function variableNodeId(SharedEnvironmentVariable $variable): string
    {
        return 'variable:'.$variable->id;
    }

    /**
     * The shared variables overview of a team: counts per scope, recently changed variables,
     * and how many resources use each variable (from the {{scope.KEY}} references in the
     * saved resource variables). Keys and counts only; values are never read out.
     *
     * @return array{scopes: array<string, array{variables: int, owners: int}>, recent: Collection<int, array<string, mixed>>, used: Collection<int, array<string, mixed>>, unused: Collection<int, array<string, mixed>>, missing: int}
     */
    public function overview(Team $team): array
    {
        $variables = SharedEnvironmentVariable::query()
            ->where('team_id', $team->id)
            ->with(['project', 'environment.project', 'server'])
            ->get()
            // Built-in server variables; the server variables page hides them too.
            ->reject(fn (SharedEnvironmentVariable $variable) => $variable->type === 'server' && in_array($variable->key, self::BUILT_IN_SERVER_KEYS, true));

        $scopes = collect(self::SCOPES)->mapWithKeys(function (string $scope) use ($variables) {
            $ofScope = $variables->where('type', $scope);
            $ownerColumn = $scope === 'team' ? 'team_id' : $scope.'_id';

            return [$scope => ['variables' => $ofScope->count(), 'owners' => $ofScope->pluck($ownerColumn)->unique()->count()]];
        })->all();

        // Resources per variable: "scope|owner id|KEY" => resource uuids.
        $idByReference = $variables->mapWithKeys(fn (SharedEnvironmentVariable $variable) => [
            $variable->type.'|'.($variable->type === 'team' ? $variable->team_id : $variable->{$variable->type.'_id'}).'|'.$variable->key => $variable->id,
        ]);
        $resources = $this->resources('team', $team);
        $resourceByMorph = $resources->mapWithKeys(fn (Model $resource) => [$resource->getMorphClass().'#'.$resource->getKey() => $resource]);
        $usedBy = [];
        $missing = [];
        foreach ($this->resourceVariables($resources) as $environmentVariable) {
            $resource = $resourceByMorph->get($environmentVariable->resourceable_type.'#'.$environmentVariable->resourceable_id);
            $owners = [
                'team' => $team->id,
                'project' => $resource->environment->project_id,
                'environment' => $resource->environment_id,
                'server' => $this->mainServer($resource)?->id,
            ];
            foreach ($owners as $scope => $ownerId) {
                foreach ($this->referencedKeys($scope, $environmentVariable->value) as $key) {
                    $reference = $scope.'|'.$ownerId.'|'.$key;
                    if ($idByReference->has($reference)) {
                        $usedBy[$idByReference->get($reference)][$resource->uuid] = true;
                    } else {
                        $missing[$reference] = true;
                    }
                }
            }
        }

        $rows = $variables->map(fn (SharedEnvironmentVariable $variable) => [
            'key' => $variable->key,
            'scope' => $variable->type,
            'owner' => match ($variable->type) {
                'team' => 'Team',
                'project' => $variable->project?->name,
                'environment' => $variable->environment ? $variable->environment->project?->name.' / '.$variable->environment->name : null,
                'server' => $variable->server?->name,
                default => null,
            },
            'link' => $this->connectLink($variable),
            'resources' => count($usedBy[$variable->id] ?? []),
            'updated_at' => $variable->updated_at,
        ]);

        return [
            'scopes' => $scopes,
            'recent' => $rows->sortByDesc('updated_at')->take(6)->values(),
            'used' => $rows->where('resources', '>', 0)->sortByDesc('resources')->take(6)->values(),
            'unused' => $rows->where('resources', 0)->sortBy('key')->values(),
            'missing' => count($missing),
        ];
    }

    /**
     * The team of the scope owner.
     */
    public function teamId(string $scope, Model $owner): int
    {
        return (int) match ($scope) {
            'team' => $owner->id,
            'project', 'server' => $owner->team_id,
            'environment' => $owner->project->team_id,
        };
    }

    public function mainServer(Model $resource): ?Server
    {
        return $resource instanceof Service ? $resource->server : $resource->destination?->server;
    }

    public function title(string $scope, Model $owner): string
    {
        return match ($scope) {
            'team' => 'Team variables',
            'project' => $owner->name.' variables',
            'environment' => $owner->project->name.' / '.$owner->name.' variables',
            'server' => $owner->name.' variables',
        };
    }

    /**
     * The canvas of the variable's scope, with the variable selected.
     */
    public function connectLink(SharedEnvironmentVariable $variable): ?string
    {
        $query = '?select='.urlencode($this->variableNodeId($variable));

        return match ($variable->type) {
            'team' => route('shared-variables.team.canvas').$query,
            'project' => $variable->project ? route('shared-variables.project.canvas', ['project_uuid' => $variable->project->uuid]).$query : null,
            'environment' => $variable->environment?->project
                ? route('shared-variables.environment.canvas', ['project_uuid' => $variable->environment->project->uuid, 'environment_uuid' => $variable->environment->uuid]).$query
                : null,
            'server' => $variable->server ? route('shared-variables.server.canvas', ['server_uuid' => $variable->server->uuid]).$query : null,
            default => null,
        };
    }

    public function pageLink(string $scope, Model $owner): string
    {
        return match ($scope) {
            'team' => route('shared-variables.team.index'),
            'project' => route('shared-variables.project.show', ['project_uuid' => $owner->uuid]),
            'environment' => route('shared-variables.environment.show', ['project_uuid' => $owner->project->uuid, 'environment_uuid' => $owner->uuid]),
            'server' => route('shared-variables.server.show', ['server_uuid' => $owner->uuid]),
        };
    }
}
