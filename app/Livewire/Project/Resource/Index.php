<?php

namespace App\Livewire\Project\Resource;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

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

        // Load projects and environments for breadcrumb navigation (avoids inline queries in view)
        $this->allProjects = Project::ownedByCurrentTeamCached();
        $this->allEnvironments = $project->environments()
            ->with([
                'applications.additional_servers',
                'applications.destination.server',
                'services',
                'services.destination.server',
                'postgresqls',
                'postgresqls.destination.server',
                'redis',
                'redis.destination.server',
                'mongodbs',
                'mongodbs.destination.server',
                'mysqls',
                'mysqls.destination.server',
                'mariadbs',
                'mariadbs.destination.server',
                'keydbs',
                'keydbs.destination.server',
                'dragonflies',
                'dragonflies.destination.server',
                'clickhouses',
                'clickhouses.destination.server',
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

        // Eager load all relationships for applications including nested ones
        $this->applications = $this->environment->applications()->with([
            'tags',
            'additional_servers.settings',
            'additional_networks',
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
        return view('livewire.project.resource.index');
    }

    public function regenerateEnvironmentResources(): mixed
    {
        try {
            if (! auth()->user()?->can('createAnyResource')) {
                $this->dispatch('error', 'You do not have permission to regenerate resources.');

                return null;
            }

            $environment = Environment::query()
                ->where('id', $this->environment->id)
                ->where('project_id', $this->project->id)
                ->firstOrFail();

            $result = $this->regenerateEnvironmentServices($environment);

            if ($result['services'] === 0) {
                $this->dispatch('error', 'No services found in this environment to regenerate.');

                return null;
            }

            if ($result['failed'] > 0) {
                $this->dispatch(
                    'error',
                    "Resources regenerated partially. Parsed {$result['parsed']} service(s), {$result['failed']} failed."
                );
            } else {
                $this->dispatch(
                    'success',
                    "Resources regenerated successfully. Parsed {$result['parsed']} service(s)."
                );
            }

            return redirectRoute($this, 'project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @return array{services:int,restored:int,parsed:int,failed:int}
     */
    private function regenerateEnvironmentServices(Environment $environment): array
    {
        $services = Service::withTrashed()
            ->where('environment_id', $environment->id)
            ->get();

        $restored = 0;
        $parsed = 0;
        $failed = 0;

        foreach ($services as $service) {
            try {
                if ($service->trashed()) {
                    $service->restore();
                    $restored++;
                }

                $service->parse();
                $parsed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return [
            'services' => $services->count(),
            'restored' => $restored,
            'parsed' => $parsed,
            'failed' => $failed,
        ];
    }
}
