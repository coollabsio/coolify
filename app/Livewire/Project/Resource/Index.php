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

    public array $parameters;

    public function mount()
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
        }

        // Load services with their tags and server
        $this->services = $this->environment->services()->with([
            'tags',
            'destination.server.settings',
        ])->get()->sortBy('name');
    }

    public function render()
    {
        return view('livewire.project.resource.index');
    }
}
