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

    public function mount()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;
        $this->environment = $environment;

        $this->applications = $this->environment->applications->load(['tags']);
        $this->applications = $this->applications->map(function ($application) {
            if (data_get($application, 'environment.project.uuid')) {
                $application->hrefLink = route('project.application.configuration', [
                    'project_uuid' => data_get($application, 'environment.project.uuid'),
                    'environment_name' => data_get($application, 'environment.name'),
                    'application_uuid' => data_get($application, 'uuid'),
                ]);
            }

            return $application;
        });
        $this->postgresqls = $this->environment->postgresqls->load(['tags'])->sortBy('name');
        $this->postgresqls = $this->postgresqls->map(function ($postgresql) {
            if (data_get($postgresql, 'environment.project.uuid')) {
                $postgresql->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($postgresql, 'environment.project.uuid'),
                    'environment_name' => data_get($postgresql, 'environment.name'),
                    'database_uuid' => data_get($postgresql, 'uuid'),
                ]);
            }

            return $postgresql;
        });
        $this->redis = $this->environment->redis->load(['tags'])->sortBy('name');
        $this->redis = $this->redis->map(function ($redis) {
            if (data_get($redis, 'environment.project.uuid')) {
                $redis->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($redis, 'environment.project.uuid'),
                    'environment_name' => data_get($redis, 'environment.name'),
                    'database_uuid' => data_get($redis, 'uuid'),
                ]);
            }

            return $redis;
        });
        $this->mongodbs = $this->environment->mongodbs->load(['tags'])->sortBy('name');
        $this->mongodbs = $this->mongodbs->map(function ($mongodb) {
            if (data_get($mongodb, 'environment.project.uuid')) {
                $mongodb->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($mongodb, 'environment.project.uuid'),
                    'environment_name' => data_get($mongodb, 'environment.name'),
                    'database_uuid' => data_get($mongodb, 'uuid'),
                ]);
            }

            return $mongodb;
        });
        $this->mysqls = $this->environment->mysqls->load(['tags'])->sortBy('name');
        $this->mysqls = $this->mysqls->map(function ($mysql) {
            if (data_get($mysql, 'environment.project.uuid')) {
                $mysql->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($mysql, 'environment.project.uuid'),
                    'environment_name' => data_get($mysql, 'environment.name'),
                    'database_uuid' => data_get($mysql, 'uuid'),
                ]);
            }

            return $mysql;
        });
        $this->mariadbs = $this->environment->mariadbs->load(['tags'])->sortBy('name');
        $this->mariadbs = $this->mariadbs->map(function ($mariadb) {
            if (data_get($mariadb, 'environment.project.uuid')) {
                $mariadb->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($mariadb, 'environment.project.uuid'),
                    'environment_name' => data_get($mariadb, 'environment.name'),
                    'database_uuid' => data_get($mariadb, 'uuid'),
                ]);
            }

            return $mariadb;
        });
        $this->keydbs = $this->environment->keydbs->load(['tags'])->sortBy('name');
        $this->keydbs = $this->keydbs->map(function ($keydb) {
            if (data_get($keydb, 'environment.project.uuid')) {
                $keydb->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($keydb, 'environment.project.uuid'),
                    'environment_name' => data_get($keydb, 'environment.name'),
                    'database_uuid' => data_get($keydb, 'uuid'),
                ]);
            }

            return $keydb;
        });
        $this->dragonflies = $this->environment->dragonflies->load(['tags'])->sortBy('name');
        $this->dragonflies = $this->dragonflies->map(function ($dragonfly) {
            if (data_get($dragonfly, 'environment.project.uuid')) {
                $dragonfly->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($dragonfly, 'environment.project.uuid'),
                    'environment_name' => data_get($dragonfly, 'environment.name'),
                    'database_uuid' => data_get($dragonfly, 'uuid'),
                ]);
            }

            return $dragonfly;
        });
        $this->clickhouses = $this->environment->clickhouses->load(['tags'])->sortBy('name');
        $this->clickhouses = $this->clickhouses->map(function ($clickhouse) {
            if (data_get($clickhouse, 'environment.project.uuid')) {
                $clickhouse->hrefLink = route('project.database.configuration', [
                    'project_uuid' => data_get($clickhouse, 'environment.project.uuid'),
                    'environment_name' => data_get($clickhouse, 'environment.name'),
                    'database_uuid' => data_get($clickhouse, 'uuid'),
                ]);
            }

            return $clickhouse;
        });
        $this->services = $this->environment->services->load(['tags'])->sortBy('name');
        $this->services = $this->services->map(function ($service) {
            if (data_get($service, 'environment.project.uuid')) {
                $service->hrefLink = route('project.service.configuration', [
                    'project_uuid' => data_get($service, 'environment.project.uuid'),
                    'environment_name' => data_get($service, 'environment.name'),
                    'service_uuid' => data_get($service, 'uuid'),
                ]);
                $service->status = $service->status();
            }

            return $service;
        });
    }

    public function render()
    {
        return view('livewire.project.resource.index');
    }
}
