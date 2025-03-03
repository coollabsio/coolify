<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use Inertia\Inertia;

class InertiaController extends Controller
{
    public function dashboard()
    {
        $servers = Server::ownedByCurrentTeam()->get();
        $projects = Project::ownedByCurrentTeam()->orderBy('created_at')->with('environments')->get();
        $projects = $projects->map(function ($project) {
            return [
                'name' => $project->name,
                'description' => $project->description,
                'uuid' => $project->uuid,
                'environments' => $project->environments()->get()->map(function ($environment) {
                    return [
                        'name' => $environment->name,
                        'uuid' => $environment->uuid,
                        'project_uuid' => $environment->project->uuid,
                    ];
                }),
            ];
        });
        $applications = Application::ownedByCurrentTeam()->orderBy('created_at')->get();
        $applications = $applications->map(function ($application) {
            return [
                'name' => $application->name,
                'description' => $application->description,
                'uuid' => $application->uuid,
            ];
        });

        $databases = collect($servers)->flatMap(function ($server) {
            return $server->databases();
        });
        $databases = $databases->map(function ($database) {
            return [
                'name' => $database->name,
                'description' => $database->description,
                'uuid' => $database->uuid,
                'type' => $database->type(),
            ];
        });

        $services = collect($servers)->flatMap(function ($server) {
            return $server->services()->get();
        });
        $services = $services->map(function ($service) {
            return [
                'name' => $service->name,
                'description' => $service->description,
                'uuid' => $service->uuid,
                'type' => $service->type(),
            ];
        });
        $servers = $servers->map(function ($server) {
            return [
                'name' => $server->name,
                'description' => $server->description,
                'uuid' => $server->uuid,
            ];
        });

        return Inertia::render('Dashboard', [
            'projects' => $projects,
            // Should not add proxy
            'servers' => $servers,
            'applications' => $applications,
            'databases' => $databases,
            'services' => $services,
        ]);
    }

    public function projects()
    {
        return Inertia::render('Projects/Index', [
            'projects' => Project::ownedByCurrentTeam()->orderBy('created_at')->get(['name', 'description', 'uuid']),
        ]);
    }

    public function project(string $project_uuid)
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('projects');
        }

        $environments = $project->environments()->get();
        if ($environments->count() === 1) {
            // $environment = $environments->first();
            // return redirect()->route('project.environment', $environment->uuid);
        }

        return Inertia::render('Projects/Project', [
            'project' => $project,
            'environments' => $environments,
        ]);
    }

    public function environment(string $project_uuid, string $environment_uuid)
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('projects');
        }
        $environment = $project->environments()->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('project', $project_uuid);
        }

        $applications = $environment->applications()->with([
            'tags',
            'additional_servers.settings',
            'additional_networks',
            'destination.server.settings',
        ])->get()->sortBy('name');

        $postgresqls = $environment->postgresqls()->get();
        $redis = $environment->redis()->get();
        $mongodbs = $environment->mongodbs()->get();
        $mysqls = $environment->mysqls()->get();
        $mariadbs = $environment->mariadbs()->get();
        $keydbs = $environment->keydbs()->get();

        $environment = $environment->loadCount([
            'applications',
            'redis',
            'postgresqls',
            'mysqls',
            'keydbs',
            'dragonflies',
            'clickhouses',
            'mariadbs',
            'mongodbs',
            'keydbs',
            'services',
        ]);
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
            ${$property} = $environment->{$relation}()->with([
                'tags',
                'destination.server.settings',
            ])->get()->sortBy('name');
        }

        // Load services with their tags and server
        $services = $environment->services()->with([
            'tags',
            'destination.server.settings',
        ])->get()->sortBy('name');

        return Inertia::render('Environment', [
            'project' => $project,
            'environment' => $environment,
            'applications' => $applications->values()->all(),
            'services' => $services->values()->all(),
            'postgresqls' => $postgresqls->values()->all(),
            'redis' => $redis->values()->all(),
            'mongodbs' => $mongodbs->values()->all(),
            'mysqls' => $mysqls->values()->all(),
            'mariadbs' => $mariadbs->values()->all(),
            'keydbs' => $keydbs->values()->all(),
        ]);
    }
}
