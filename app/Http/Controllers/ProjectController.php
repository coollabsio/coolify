<?php

namespace App\Http\Controllers;

use Spatie\Activitylog\Models\Activity;

class ProjectController extends Controller
{
    public function environments()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $project->load(['environments']);
        if (count($project->environments) == 1) {
            return redirect()->route('project.resources', ['project_uuid' => $project->uuid, 'environment_name' => $project->environments->first()->name]);
        }
        return view('project.environments', ['project' => $project]);
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
        return view('project.resources', ['project' => $project, 'environment' => $environment]);
    }
}
