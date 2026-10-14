<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Project;
use App\Models\ResourceLink;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the project canvas: every resource of the project's environments, one zone per server,
 * grouped by environment. Cards keep the positions and links saved on their environment canvas;
 * admins change them through the environment canvas endpoints of each card.
 */
class ProjectCanvas
{
    public function __construct(private EnvironmentCanvas $canvas, private ServerCanvas $servers) {}

    /**
     * The canvas of every project of a team: one zone per project, cards grouped by environment.
     *
     * @return array{title: string, title_link: string, can_edit: bool, can_move: false, layout: 'server', zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, positions: array{}}
     */
    public function teamPayload(int $teamId, bool $canReadSensitive, bool $canEdit = false): array
    {
        $projects = Project::query()->where('team_id', $teamId)->orderBy('name')->get(['id', 'uuid', 'name', 'team_id']);
        $environments = Environment::query()->whereIn('project_id', $projects->pluck('id'))
            ->get(['id', 'uuid', 'name', 'project_id']);
        $resources = $this->servers->loadResources($environments->pluck('id'));
        $links = ResourceLink::query()->whereIn('environment_id', $environments->pluck('id'))->orderBy('id')->get();
        $graph = $this->canvas->graph($resources, $links, $canReadSensitive);

        $grouping = $resources->map(fn (Model $resource) => [
            'zone' => $resource->environment->project->uuid,
            'extra_zones' => [],
            'context' => $resource->environment->name,
            'context_link' => route('project.resource.index', ['project_uuid' => $resource->environment->project->uuid, 'environment_uuid' => $resource->environment->uuid]),
            ...$this->owner($resource),
        ]);

        return [
            'title' => 'Projects',
            'title_link' => route('project.index'),
            'can_edit' => $canEdit,
            // Environment groups are auto-placed; cards inside a group keep their environment canvas arrangement.
            'can_move' => false,
            // Same grouped layout as the server canvas, with projects as the zones.
            'layout' => 'server',
            'zones' => $projects->map(fn (Project $project) => [
                'id' => $project->uuid,
                'name' => $project->name,
                'icon' => 'projects',
                'link' => route('project.show', ['project_uuid' => $project->uuid]),
            ])->values()->all(),
            'nodes' => collect($graph['nodes'])->map(fn (array $node) => [...$node, ...$grouping->get($node['id'])])->values()->all(),
            'links' => $graph['links'],
            'positions' => [],
        ];
    }

    /**
     * @return array{title: string, title_link: string, can_edit: bool, can_move: false, layout: 'server', zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, positions: array{}}
     */
    public function payload(Project $project, bool $canReadSensitive, bool $canEdit = false): array
    {
        $environments = $project->environments()->get(['id', 'uuid', 'name', 'project_id']);
        $resources = $this->servers->loadResources($environments->pluck('id'));
        $links = ResourceLink::query()->whereIn('environment_id', $environments->pluck('id'))->orderBy('id')->get();
        $graph = $this->canvas->graph($resources, $links, $canReadSensitive);

        $context = $resources->map(fn (Model $resource) => [
            'context' => $resource->environment->name,
            'context_link' => route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $resource->environment->uuid]),
            ...$this->owner($resource),
        ]);

        return [
            'title' => $project->name,
            'title_link' => route('project.show', ['project_uuid' => $project->uuid]),
            'can_edit' => $canEdit,
            'can_move' => false,
            // Groups cards by environment inside each server zone, like the server canvas.
            'layout' => 'server',
            'zones' => $graph['zones'],
            'nodes' => collect($graph['nodes'])->map(fn (array $node) => [...$node, ...$context->get($node['id'])])->values()->all(),
            'links' => $graph['links'],
            'positions' => [],
        ];
    }

    /**
     * Project and environment of a card: the canvas saves links on that environment canvas.
     *
     * @return array{project: array{uuid: string, name: string}, environment: array{uuid: string, name: string}}
     */
    private function owner(Model $resource): array
    {
        return [
            'project' => ['uuid' => $resource->environment->project->uuid, 'name' => $resource->environment->project->name],
            'environment' => ['uuid' => $resource->environment->uuid, 'name' => $resource->environment->name],
        ];
    }
}
