<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function environments()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first()->load(['environments']);
        if (!$project) {
            return redirect()->route('home');
        }
        return view('project.environments', ['project' => $project]);
    }
    public function resources()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('home');
        }
        return view('project.resources', ['project' => $project, 'environment' => $environment]);
    }
    public function application()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (!$environment) {
            return redirect()->route('home');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (!$application) {
            return redirect()->route('home');
        }
        return view('project.application', ['application' => $application, 'deployments' => $application->deployments()]);
    }

    public function deployment()
    {
        $deployment_uuid = request()->route('deployment_uuid');

        $application = Application::where('uuid', request()->route('application_uuid'))->first();
        if (!$application) {
            return redirect()->route('home');
        }
        $activity = $application->get_deployment($deployment_uuid);
        return view('project.deployment', ['activity' => $activity]);
    }
}
