<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function environments()
    {
        $project_uuid = request()->route('project_uuid');
        $project = session('currentTeam')->projects->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('home');
        }
        return view('project.environments', ['project' => $project]);
    }
    public function resources()
    {
        $project_uuid = request()->route('project_uuid');
        $project = session('currentTeam')->projects->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $environment = $project->environments->where('name', request()->route('environment_name'))->first();
        return view('project.resources', ['project' => $project, 'environment' => $environment]);
    }
    public function application()
    {
        $project_uuid = request()->route('project_uuid');
        $environment_name = request()->route('environment_name');
        $application_uuid = request()->route('application_uuid');
        $project = session('currentTeam')->projects->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $environment = $project->environments->where('name', $environment_name)->first();
        if (!$environment) {
            return redirect()->route('home');
        }
        $application = $environment->applications->where('uuid', $application_uuid)->first();
        if (!$application) {
            return redirect()->route('home');
        }
        return view('project.application', ['project' => $project, 'application' => $application, 'deployments' => $application->deployments()]);
    }
    public function database()
    {
        $project_uuid = request()->route('project_uuid');
        $environment_name = request()->route('environment_name');
        $database_uuid = request()->route('database_uuid');
        $project = session('currentTeam')->projects->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $environment = $project->environments->where('name', $environment_name)->first();
        if (!$environment) {
            return redirect()->route('home');
        }
        $database = $environment->databases->where('uuid', $database_uuid)->first();
        if (!$database) {
            return redirect()->route('home');
        }

        return view('project.database', ['project' => $project, 'database' => $database]);
    }
    public function service()
    {
        $project_uuid = request()->route('project_uuid');
        $environment_name = request()->route('environment_name');
        $service_uuid = request()->route('service_uuid');

        $project = session('currentTeam')->projects->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $environment = $project->environments->where('name', $environment_name)->first();
        if (!$environment) {
            return redirect()->route('home');
        }
        $service = $environment->services->where('uuid', $service_uuid)->first();
        if (!$service) {
            return redirect()->route('home');
        }

        return view('project.service', ['project' => $project, 'service' => $service]);
    }
    public function deployment()
    {
        $project_uuid = request()->route('project_uuid');
        $environment_name = request()->route('environment_name');
        $application_uuid = request()->route('application_uuid');
        $deployment_uuid = request()->route('deployment_uuid');

        $project = session('currentTeam')->projects->where('uuid', $project_uuid)->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $environment = $project->environments->where('name', $environment_name)->first();
        if (!$environment) {
            return redirect()->route('home');
        }
        $application = $environment->applications->where('uuid', $application_uuid)->first();
        if (!$application) {
            return redirect()->route('home');
        }
        $activity = $application->get_deployment($deployment_uuid);
        return view('project.deployment', ['project' => $project, 'activity' => $activity]);
    }
}
