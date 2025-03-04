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
        $project = Project::ownedByCurrentTeam()
            ->where('uuid', $project_uuid)
            ->select(['id', 'name', 'description', 'uuid'])
            ->with('environments:id,name,uuid,project_id')
            ->firstOrFail();

        if (! $project) {
            return redirect()->route('projects');
        }

        return Inertia::render('Projects/Project', [
            'project' => $project,
            'environments' => $project->environments,
        ]);
    }

    public function environment(string $project_uuid, string $environment_uuid)
    {
        try {
            $project = Project::ownedByCurrentTeam()
                ->where('uuid', $project_uuid)
                ->select(['id', 'name', 'uuid'])
                ->firstOrFail();

            $environment = $project->environments()
                ->where('uuid', $environment_uuid)
                ->select(['id', 'name', 'uuid'])
                ->firstOrFail();
            $baseFields = ['id', 'name', 'uuid'];
            $baseRelations = [
                'tags:id,name',
            ];

            $applications = $environment->applications()
                ->select($baseFields)
                ->with($baseRelations)
                ->get()
                ->map(fn ($model) => tap($model, fn ($m) => $m->setAppends([])))
                ->sortBy('name');

            $databaseTypes = [
                'postgresqls',
                'redis',
                'mongodbs',
                'mysqls',
                'mariadbs',
                'keydbs',
            ];

            $resources = [];
            foreach ($databaseTypes as $type) {
                $resources[$type] = $environment->{$type}()
                    ->select($baseFields)
                    ->with($baseRelations)
                    ->get()
                    ->map(fn ($model) => tap($model, fn ($m) => $m->setAppends([])))
                    ->sortBy('name')
                    ->values()
                    ->all();
            }

            $services = $environment->services()
                ->select($baseFields)
                ->with($baseRelations)
                ->get()
                ->map(fn ($model) => tap($model, fn ($m) => $m->setAppends([])))
                ->sortBy('name');

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

            return Inertia::render('Environment', [
                'project' => $project,
                'environment' => $environment,
                'applications' => $applications->values()->all(),
                'services' => $services->values()->all(),
                'postgresqls' => $resources['postgresqls'],
                'redis' => $resources['redis'],
                'mongodbs' => $resources['mongodbs'],
                'mysqls' => $resources['mysqls'],
                'mariadbs' => $resources['mariadbs'],
                'keydbs' => $resources['keydbs'],
            ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
