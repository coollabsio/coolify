<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the tags canvas of a team: the tags on the left, the resources on the right, and a link
 * for every tag on a resource. Same layout and wording slots as the shared variables canvas.
 */
class TagCanvas
{
    public function __construct(private SharedVariableCanvas $connect, private ServerCanvas $serverCanvas) {}

    /**
     * @return array{title: string, title_link: string, can_edit: bool, can_move: false, layout: 'connect', zones: array<int, array<string, mixed>>, nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, positions: array{}}
     */
    public function payload(int $teamId, bool $canEdit): array
    {
        $tags = $this->tags($teamId);
        $resources = $this->resources($teamId);

        return [
            'title' => 'Tags',
            'title_link' => route('tags.show'),
            'can_edit' => $canEdit,
            'can_move' => false,
            'layout' => 'connect',
            'zones' => [
                ['id' => SharedVariableCanvas::SOURCES_ZONE, 'name' => 'Tags', 'icon' => 'tag'],
                // The canvas moves the tagged resources into this zone.
                ['id' => SharedVariableCanvas::CONNECTED_ZONE, 'name' => 'Tagged resources', 'icon' => 'link'],
                ['id' => SharedVariableCanvas::RESOURCES_ZONE, 'name' => 'Other resources', 'icon' => 'service'],
            ],
            'nodes' => $tags
                ->map(fn (Tag $tag) => [
                    'id' => $this->nodeId($tag),
                    'type' => 'tag',
                    'name' => $tag->name,
                    'link' => route('tags.show', ['tagName' => $tag->name]),
                    'missing' => false,
                    'zone' => SharedVariableCanvas::SOURCES_ZONE,
                    'extra_zones' => [],
                    'status' => null,
                ])
                ->concat($this->connect->resourceNodes($resources))
                ->values()
                ->all(),
            'links' => $this->links($tags, $resources),
            'positions' => [],
        ];
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tags(int $teamId): Collection
    {
        return Tag::query()->where('team_id', $teamId)->orderBy('name')->get();
    }

    /**
     * Top-level resources of the team with their tags, keyed by uuid.
     *
     * @return Collection<string, Model>
     */
    public function resources(int $teamId): Collection
    {
        $environmentIds = Environment::query()->whereHas('project', fn ($query) => $query->where('team_id', $teamId))->pluck('id');
        $resources = $this->serverCanvas->loadResources($environmentIds);
        $resources->groupBy(fn (Model $resource) => $resource::class)
            ->each(fn (Collection $group) => (new EloquentCollection($group->values()->all()))->load('tags'));

        return $resources->sortBy(fn (Model $resource) => mb_strtolower($resource->name))->keyBy('uuid');
    }

    /**
     * @param  Collection<int, Tag>  $tags
     * @param  Collection<string, Model>  $resources
     * @return array<int, array{id: string, source: string, target: string, key: string, keys: array{}, label: null, removable: true, missing: false}>
     */
    public function links(Collection $tags, Collection $resources): array
    {
        $teamTags = $tags->keyBy('id');

        return $resources
            ->flatMap(fn (Model $resource) => $resource->tags
                ->filter(fn (Tag $tag) => $teamTags->has($tag->id))
                ->map(fn (Tag $tag) => $this->link($tag, $resource)))
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, source: string, target: string, key: string, keys: array{}, label: null, removable: true, missing: false}
     */
    public function link(Tag $tag, Model $resource): array
    {
        $source = $this->nodeId($tag);

        return ['id' => $source.'|'.$resource->uuid, 'source' => $source, 'target' => $resource->uuid, 'key' => $tag->name, 'keys' => [], 'label' => null, 'removable' => true, 'missing' => false];
    }

    public function nodeId(Tag $tag): string
    {
        return 'tag:'.$tag->uuid;
    }

    /**
     * The tags canvas with the tag selected.
     */
    public function connectLink(Tag $tag): string
    {
        return route('tags.canvas').'?select='.urlencode($this->nodeId($tag));
    }
}
