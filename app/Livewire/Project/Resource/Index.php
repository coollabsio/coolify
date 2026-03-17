<?php

namespace App\Livewire\Project\Resource;

use App\Models\Environment;
use App\Models\Project;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class Index extends Component
{
    public Project $project;

    public Environment $environment;

    public Collection $applications;

    public Collection $postgresqls;

    public Collection $redis;

    public Collection $mongodbs;

    public Collection $mysqls;

    public Collection $mariadbs;

    public Collection $keydbs;

    public Collection $dragonflies;

    public Collection $clickhouses;

    public Collection $services;

    public Collection $allProjects;

    public Collection $allEnvironments;

    public array $parameters;

    public bool $canAccessTerminal;

    public function mount(): void
    {
        $this->applications = $this->postgresqls = $this->redis = $this->mongodbs = $this->mysqls = $this->mariadbs = $this->keydbs = $this->dragonflies = $this->clickhouses = $this->services = collect();
        $this->parameters = get_route_parameters();
        $project = currentTeam()
            ->projects()
            ->select('id', 'uuid', 'team_id', 'name')
            ->where('uuid', request()->route('project_uuid'))
            ->firstOrFail();
        $environment = $project->environments()
            ->select('id', 'uuid', 'name', 'project_id')
            ->where('uuid', request()->route('environment_uuid'))
            ->firstOrFail();

        $this->project = $project;
        $this->canAccessTerminal = Gate::allows('canAccessTerminal');

        $this->allProjects = Project::ownedByCurrentTeamCached();
        $this->allEnvironments = $project->environments()
            ->select('id', 'uuid', 'name', 'project_id')
            ->withCount([
                'applications', 'postgresqls', 'redis', 'mongodbs',
                'mysqls', 'mariadbs', 'keydbs', 'dragonflies',
                'clickhouses', 'services',
            ])->get();

        $this->environment = $environment->loadCount([
            'applications',
            'redis',
            'postgresqls',
            'mysqls',
            'keydbs',
            'dragonflies',
            'clickhouses',
            'mariadbs',
            'mongodbs',
            'services',
        ]);

        $projectUuid = $this->project->uuid;
        $environmentUuid = $this->environment->uuid;

        $this->applications = $this->environment->applications()->with([
            'tags',
            'additional_servers.settings',
            'additional_networks',
            'destination.server.settings',
            'settings',
        ])->get()->sortBy('name');
        $this->applications = $this->applications->map(function ($application) use ($projectUuid, $environmentUuid) {
            $application->hrefLink = route('project.application.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'application_uuid' => $application->uuid,
            ]);

            return $application;
        });

        $databaseTypes = [
            'postgresqls' => 'postgresqls',
            'redis' => 'redis',
            'mongodbs' => 'mongodbs',
            'mysqls' => 'mysqls',
            'mariadbs' => 'mariadbs',
            'keydbs' => 'keydbs',
            'dragonflies' => 'dragonflies',
            'clickhouses' => 'clickhouses',
        ];

        foreach ($databaseTypes as $property => $relation) {
            $this->{$property} = $this->environment->{$relation}()->with([
                'tags',
                'destination.server.settings',
            ])->get()->sortBy('name');
            $this->{$property} = $this->{$property}->map(function ($db) use ($projectUuid, $environmentUuid) {
                $db->hrefLink = route('project.database.configuration', [
                    'project_uuid' => $projectUuid,
                    'database_uuid' => $db->uuid,
                    'environment_uuid' => $environmentUuid,
                ]);

                return $db;
            });
        }

        $this->services = $this->environment->services()->with([
            'tags',
            'destination.server.settings',
        ])->get()->sortBy('name');
        $this->services = $this->services->map(function ($service) use ($projectUuid, $environmentUuid) {
            $service->hrefLink = route('project.service.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'service_uuid' => $service->uuid,
            ]);

            return $service;
        });
    }

    #[Renderless]
    public function loadBreadcrumbResources(int $environmentId, int $page = 1): array
    {
        $perPage = 20;
        $environment = Environment::findOrFail($environmentId);

        if ($environment->project_id !== $this->project->id) {
            abort(403);
        }

        $backupableTypes = [
            StandalonePostgresql::class,
            StandaloneMongodb::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
        ];

        $resources = collect();

        $apps = $environment->applications()
            ->select('id', 'uuid', 'name', 'environment_id', 'destination_id', 'destination_type')
            ->with(['destination.server:id,name', 'additional_servers:id'])
            ->get();

        foreach ($apps as $app) {
            $resources->push([
                'uuid' => $app->uuid,
                'name' => $app->name,
                'type' => 'application',
                'serverName' => $app->destination?->server?->name,
                'hasAdditionalServers' => $app->additional_servers->isNotEmpty(),
                'canBackup' => false,
            ]);
        }

        $dbTypes = ['postgresqls', 'redis', 'mongodbs', 'mysqls', 'mariadbs', 'keydbs', 'dragonflies', 'clickhouses'];

        foreach ($dbTypes as $relation) {
            $dbs = $environment->{$relation}()
                ->select('id', 'uuid', 'name', 'environment_id', 'destination_id', 'destination_type')
                ->with(['destination.server:id,name'])
                ->get();

            foreach ($dbs as $db) {
                $resources->push([
                    'uuid' => $db->uuid,
                    'name' => $db->name,
                    'type' => 'database',
                    'serverName' => $db->destination?->server?->name,
                    'hasAdditionalServers' => false,
                    'canBackup' => in_array($db->getMorphClass(), $backupableTypes),
                ]);
            }
        }

        $services = $environment->services()
            ->select('id', 'uuid', 'name', 'environment_id', 'destination_id', 'destination_type')
            ->with(['destination.server:id,name'])
            ->get();

        foreach ($services as $service) {
            $resources->push([
                'uuid' => $service->uuid,
                'name' => $service->name,
                'type' => 'service',
                'serverName' => $service->destination?->server?->name,
                'hasAdditionalServers' => false,
                'canBackup' => false,
            ]);
        }

        $sorted = $resources->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $total = $sorted->count();
        $offset = ($page - 1) * $perPage;
        $paged = $sorted->slice($offset, $perPage)->values();

        return [
            'resources' => $paged->toArray(),
            'hasMore' => ($offset + $perPage) < $total,
        ];
    }

    public function render()
    {
        return view('livewire.project.resource.index');
    }
}
