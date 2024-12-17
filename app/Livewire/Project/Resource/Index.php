<?php

namespace App\Livewire\Project\Resource;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Component;

class Index extends Component
{
    public Project $project;

    public Environment $environment;

    public $applications = [];

    public $postgresqls = [];

    public $redis = [];

    public $mongodbs = [];

    public $mysqls = [];

    public $mariadbs = [];

    public $keydbs = [];

    public $dragonflies = [];

    public $clickhouses = [];

    public $services = [];

    public array $parameters;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;
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

        // Eager load all relationships for applications including nested ones
        $this->applications = $this->environment->applications()->with([
            'tags',
            'additional_servers.settings',
            'additional_networks',
            'destination.server.settings',
            'settings',
        ])->get()->sortBy('name');
        $this->applications = $this->applications->map(function ($application) {
            $application->hrefLink = route('project.application.configuration', [
                    'project_uuid' => data_get($application, 'environment.project.uuid'),
                    'environment_uuid' => data_get($application, 'environment.uuid'),
                    'application_uuid' => data_get($application, 'uuid'),
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

        // Load all server-related data first to prevent duplicate queries
        $serverData = $this->environment->applications()
            ->with(['destination.server.settings'])
            ->get()
            ->pluck('destination.server')
            ->filter()
            ->unique('id');

        foreach ($databaseTypes as $property => $relation) {
            $this->{$property} = $this->environment->{$relation}()->with([
                'tags',
                'destination.server.settings',
            ])->get()->sortBy('name');
            $this->{$property} = $this->{$property}->map(function ($db) {
                $db->hrefLink = route('project.database.configuration', [
                    'project_uuid' => $this->project->uuid,
                    'database_uuid' => $db->uuid,
                    'environment_uuid' => data_get($this->environment, 'uuid'),
                ]);
                return $db;
            });
        }

        // Load services with their tags and server
        $this->services = $this->environment->services()->with([
            'tags',
            'destination.server.settings',
        ])->get()->sortBy('name');
        $this->services = $this->services->map(function ($service) {
            $service->hrefLink = route('project.service.configuration', [
                    'project_uuid' => data_get($service, 'environment.project.uuid'),
                    'environment_uuid' => data_get($service, 'environment.uuid'),
                    'service_uuid' => data_get($service, 'uuid'),
            ]);
            return $service;
        });
    }

    public function render()
    {
        return view('livewire.project.resource.index');
    }
}
