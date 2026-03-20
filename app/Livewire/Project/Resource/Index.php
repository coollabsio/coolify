<?php

namespace App\Livewire\Project\Resource;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Project $project;

    public Environment $environment;

    public Collection $allProjects;

    public Collection $allEnvironments;

    public array $parameters;

    protected Collection $applications;

    protected Collection $postgresqls;

    protected Collection $redis;

    protected Collection $mongodbs;

    protected Collection $mysqls;

    protected Collection $mariadbs;

    protected Collection $keydbs;

    protected Collection $dragonflies;

    protected Collection $clickhouses;

    protected Collection $services;

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

        // Load projects and environments for breadcrumb navigation
        $this->allProjects = Project::ownedByCurrentTeamCached();
        $this->allEnvironments = $project->environments()
            ->select('id', 'uuid', 'name', 'project_id')
            ->with([
                'applications:id,uuid,name,environment_id',
                'services:id,uuid,name,environment_id',
                'postgresqls:id,uuid,name,environment_id',
                'redis:id,uuid,name,environment_id',
                'mongodbs:id,uuid,name,environment_id',
                'mysqls:id,uuid,name,environment_id',
                'mariadbs:id,uuid,name,environment_id',
                'keydbs:id,uuid,name,environment_id',
                'dragonflies:id,uuid,name,environment_id',
                'clickhouses:id,uuid,name,environment_id',
            ])
            ->get();

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

        // Eager load relationships for applications
        $this->applications = $this->environment->applications()->with([
            'tags',
            'destination.server.settings',
            'settings',
        ])->get()->sortBy('name');
        $projectUuid = $this->project->uuid;
        $environmentUuid = $this->environment->uuid;
        $this->applications = $this->applications->map(function ($application) use ($projectUuid, $environmentUuid) {
            $application->hrefLink = route('project.application.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'application_uuid' => $application->uuid,
            ]);

            return $application;
        });

        // Load all database resources in a single query per type
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

        // Load services with their tags and server
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

    public function render()
    {
        return view('livewire.project.resource.index', [
            'applications' => $this->applications,
            'postgresqls' => $this->postgresqls,
            'redis' => $this->redis,
            'mongodbs' => $this->mongodbs,
            'mysqls' => $this->mysqls,
            'mariadbs' => $this->mariadbs,
            'keydbs' => $this->keydbs,
            'dragonflies' => $this->dragonflies,
            'clickhouses' => $this->clickhouses,
            'services' => $this->services,
            'applicationsJs' => $this->toSearchableArray($this->applications),
            'postgresqlsJs' => $this->toSearchableArray($this->postgresqls),
            'redisJs' => $this->toSearchableArray($this->redis),
            'mongodbsJs' => $this->toSearchableArray($this->mongodbs),
            'mysqlsJs' => $this->toSearchableArray($this->mysqls),
            'mariadbsJs' => $this->toSearchableArray($this->mariadbs),
            'keydbsJs' => $this->toSearchableArray($this->keydbs),
            'dragonfliesJs' => $this->toSearchableArray($this->dragonflies),
            'clickhousesJs' => $this->toSearchableArray($this->clickhouses),
            'servicesJs' => $this->toSearchableArray($this->services),
        ]);
    }

    private function toSearchableArray(Collection $items): array
    {
        return $items->map(fn ($item) => [
            'uuid' => $item->uuid,
            'name' => $item->name,
            'fqdn' => $item->fqdn ?? null,
            'description' => $item->description ?? null,
            'status' => $item->status ?? '',
            'server_status' => $item->server_status ?? null,
            'hrefLink' => $item->hrefLink ?? '',
            'destination' => [
                'server' => [
                    'name' => $item->destination?->server?->name ?? 'Unknown',
                ],
            ],
            'tags' => $item->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()->toArray(),
        ])->values()->toArray();
    }
}
