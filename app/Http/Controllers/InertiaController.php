<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Server;
use Inertia\Inertia;

class InertiaController extends Controller
{
    public function dashboard()
    {
        $servers = Server::isUsable()->get();
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
                    ];
                }),
            ];
        });
        $destinations = collect($servers)->flatMap(function ($server) {
            return $server->destinations();
        });
        $destinations = $destinations->map(function ($destination) {
            return [
                'name' => $destination->name,
                'description' => $destination->description,
                'uuid' => $destination->uuid,
                'type' => get_class($destination),
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
            'sources' => currentTeam()->sources(),
            'destinations' => $destinations,
        ]);
    }

    public function projects()
    {
        return Inertia::render('Projects', [
            'projects' => Project::ownedByCurrentTeam()->orderBy('created_at')->get(['name', 'description', 'uuid']),
        ]);
    }

    public function project(string $project_uuid)
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('projects');
        }

        $environments = $project->environments()->get();
        if ($environments->count() === 1) {
            // $environment = $environments->first();
            // return redirect()->route('project.environment', $environment->uuid);
        }
        return Inertia::render('Project', [
            'project' => $project,
            'environments' => $environments,
        ]);
    }
}
