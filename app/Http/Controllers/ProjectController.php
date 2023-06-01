<?php

namespace App\Http\Controllers;

use App\Models\Project;

class ProjectController extends Controller
{
    public function all()
    {
        $team_id = session('currentTeam')->id;

        $projects = Project::where('team_id', $team_id)->get();
        return view('projects', ['projects' => $projects]);
    }

    public function show()
    {
        $project_uuid = request()->route('project_uuid');
        $team_id = session('currentTeam')->id;

        $project = Project::where('team_id', $team_id)->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $project->load(['environments']);
        if (count($project->environments) == 1) {
            return redirect()->route('project.resources', ['project_uuid' => $project->uuid, 'environment_name' => $project->environments->first()->name]);
        }
        return view('project.show', ['project' => $project]);
    }

    public function new()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('dashboard');
        }

        $type = request()->query('type');

        return view('project.new', [
            'type' => $type
        ]);
    }
    public function resources()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
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
