<?php

namespace App\Livewire\Destination;

use App\Models\Application;
use App\Models\BaseModel;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Resources extends Component
{
    #[Locked]
    public $destination;

    public array $resources = [];

    public function mount(string $destination_uuid)
    {
        try {
            $destination = find_destination_for_current_team($destination_uuid);
            if (! $destination) {
                return redirect()->route('destination.index');
            }
            if (! $destination instanceof StandaloneDocker) {
                return redirect()->route('destination.show', ['destination_uuid' => $destination->uuid]);
            }

            $this->destination = $destination;
            $this->loadResources();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Load applications, services, and database resources deployed to the standalone Docker destination.
     *
     * @return void Populates the resources property for display.
     */
    public function loadResources(): void
    {
        $this->resources = $this->collectResources([
            $this->destination->applications,
            $this->destination->services,
            $this->destination->postgresqls,
            $this->destination->redis,
            $this->destination->mongodbs,
            $this->destination->mysqls,
            $this->destination->mariadbs,
            $this->destination->keydbs,
            $this->destination->dragonflies,
            $this->destination->clickhouses,
        ]);
    }

    /**
     * @param  array<int, iterable<Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse>>  $groups
     * @return array<int, array{uuid:string,type:string,name:string,project:string|null,environment:string|null,url:string|null,search:string}>
     */
    protected function collectResources(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group as $resource) {
                $rows[] = $this->resourceRow($resource);
            }
        }

        return $rows;
    }

    /**
     * @param  Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse  $resource
     * @return array{uuid:string,type:string,name:string,project:string|null,environment:string|null,url:string|null,search:string}
     */
    protected function resourceRow(BaseModel $resource): array
    {
        $type = match (true) {
            $resource instanceof Application => 'application',
            $resource instanceof Service => 'service',
            default => 'database',
        };
        $environment = $resource->environment;
        $project = $environment?->project;
        $routeName = "project.{$type}.configuration";
        $url = ($project && $environment)
            ? route($routeName, [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                "{$type}_uuid" => $resource->uuid,
            ])
            : null;

        return [
            'uuid' => $resource->uuid,
            'type' => $type,
            'name' => $resource->name,
            'project' => $project?->name,
            'environment' => $environment?->name,
            'url' => $url,
            'search' => strtolower(implode(' ', array_filter([
                $type,
                $resource->name,
                $project?->name,
                $environment?->name,
            ]))),
        ];
    }

    public function render(): View
    {
        return view('livewire.destination.resources');
    }
}
