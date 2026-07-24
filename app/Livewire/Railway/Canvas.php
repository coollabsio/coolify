<?php

namespace App\Livewire\Railway;

use App\Models\Environment;
use App\Models\Project;
use App\Models\RailwayCanvasPosition;
use App\Support\RailwayResourceMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.railway')]
class Canvas extends Component
{
    public Project $project;

    public Environment $environment;

    public Collection $allProjects;

    public Collection $allEnvironments;

    /** @var array<int, array{source: string, target: string}> */
    public array $edges = [];

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $project = currentTeam()
            ->projects()
            ->where('uuid', $project_uuid)
            ->firstOrFail();

        $environment = $project->environments()
            ->where('uuid', $environment_uuid)
            ->firstOrFail();

        $this->project = $project;
        $this->environment = $environment;

        $this->allProjects = Project::ownedByCurrentTeamCached();
        $this->allEnvironments = $project->environments()->select('id', 'uuid', 'name', 'project_id')->get();

        $this->edges = $this->inferEdges(RailwayResourceMapper::resourcesFor($environment));
    }

    public function getListeners(): array
    {
        $teamId = currentTeam()->id;
        $userId = auth()->id();

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => '$refresh',
            "echo-private:team.{$teamId},ServiceStatusChanged" => '$refresh',
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
        ];
    }

    /**
     * Persist a node's position (best-effort — no-op if the table isn't migrated yet).
     */
    public function savePosition(string $resourceUuid, int $x, int $y): void
    {
        try {
            if (! Schema::hasTable('railway_canvas_positions')) {
                return;
            }

            RailwayCanvasPosition::updateOrCreate(
                ['environment_id' => $this->environment->id, 'resource_uuid' => $resourceUuid],
                ['x' => $x, 'y' => $y],
            );
        } catch (\Throwable $e) {
            // Layout persistence is non-critical; the client keeps a localStorage copy.
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildNodes(): array
    {
        $resources = RailwayResourceMapper::resourcesFor($this->environment);
        $positions = $this->savedPositions();

        return $resources->map(function ($resource) use ($positions) {
            $node = RailwayResourceMapper::toNode($resource, $this->project->uuid, $this->environment->uuid);
            $pos = $positions[$resource->uuid] ?? null;
            $node['x'] = $pos['x'] ?? null;
            $node['y'] = $pos['y'] ?? null;

            return $node;
        })->values()->all();
    }

    /**
     * @return array<string, array{x: int, y: int}>
     */
    protected function savedPositions(): array
    {
        try {
            if (! Schema::hasTable('railway_canvas_positions')) {
                return [];
            }

            return RailwayCanvasPosition::query()
                ->where('environment_id', $this->environment->id)
                ->get()
                ->keyBy('resource_uuid')
                ->map(fn ($p) => ['x' => $p->x, 'y' => $p->y])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Best-effort connection inference: an edge exists when a source resource
     * references another resource's internal id or public host in its variables.
     *
     * @param  Collection<int, Model>  $resources
     * @return array<int, array{source: string, target: string}>
     */
    protected function inferEdges(Collection $resources): array
    {
        try {
            $hostIndex = [];
            foreach ($resources as $resource) {
                $hostIndex[$resource->uuid] = $resource->uuid;
                foreach (explode(',', (string) ($resource->fqdn ?? '')) as $fqdn) {
                    $host = explode('/', preg_replace('#^https?://#', '', trim($fqdn)))[0];
                    if (strlen($host) >= 6) {
                        $hostIndex[$host] = $resource->uuid;
                    }
                }
            }

            $edges = [];
            $seen = [];
            foreach ($resources as $resource) {
                if (RailwayResourceMapper::kind($resource) === 'database') {
                    continue;
                }
                if (! method_exists($resource, 'environment_variables')) {
                    continue;
                }

                $vars = $resource->environment_variables()->limit(120)->get();
                foreach ($vars as $var) {
                    try {
                        $value = (string) $var->value;
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if ($value === '') {
                        continue;
                    }
                    foreach ($hostIndex as $host => $targetUuid) {
                        if ($targetUuid === $resource->uuid || strlen((string) $host) < 6) {
                            continue;
                        }
                        if (str_contains($value, (string) $host)) {
                            $key = $resource->uuid.'->'.$targetUuid;
                            if (! isset($seen[$key])) {
                                $seen[$key] = true;
                                $edges[] = ['source' => $resource->uuid, 'target' => $targetUuid];
                            }
                        }
                    }
                }
            }

            return $edges;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function render()
    {
        return view('livewire.railway.canvas', [
            'nodes' => $this->buildNodes(),
        ]);
    }
}
