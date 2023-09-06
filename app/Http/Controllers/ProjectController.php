<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Server;

class ProjectController extends Controller
{
    public function all()
    {
        return view('projects', [
            'projects' => Project::ownedByCurrentTeam()->get(),
            'servers' => Server::ownedByCurrentTeam()->count(),
        ]);
    }

    public function edit()
    {
        $projectUuid = request()->route('project_uuid');
        $teamId = currentTeam()->id;
        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        return view('project.edit', ['project' => $project]);
    }

    public function show()
    {
        $projectUuid = request()->route('project_uuid');
        $teamId = currentTeam()->id;

        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $project->load(['environments']);
        return view('project.show', ['project' => $project]);
    }

    public function new()
    {
        $type = request()->query('type');
        $destination_uuid = request()->query('destination');
        $server = requesT()->query('server');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        if (in_array($type, DATABASE_TYPES)) {
            $standalone_postgresql = create_standalone_postgresql($environment->id, $destination_uuid);
            return redirect()->route('project.database.configuration', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'database_uuid' => $standalone_postgresql->uuid,
            ]);
        }
        return view('project.new', [
            'type' => $type
        ]);
    }

    public function resources()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        return view('project.resources', [
            'project' => $project,
            'environment' => $environment
        ]);
    }
}
