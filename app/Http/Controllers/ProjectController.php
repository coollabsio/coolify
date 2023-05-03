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

    public function resources_new()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        return view('project.new', ['project' => $project, 'environment' => $environment, 'type' => 'resource']);
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

    public function application_configuration()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (!$application) {
            return redirect()->route('dashboard');
        }
        return view('project.application.configuration', ['application' => $application]);
    }
    public function application_deployments()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (!$application) {
            return redirect()->route('dashboard');
        }
        return view('project.application.deployments', ['application' => $application, 'deployments' => $application->deployments()]);
    }

    public function application_deployment()
    {
        $deployment_uuid = request()->route('deployment_uuid');

        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (!$application) {
            return redirect()->route('dashboard');
        }

        $activity = Activity::query()
            ->where('properties->type', '=', 'deployment')
            ->where('properties->uuid', '=', $deployment_uuid)
            ->first();

        return view('project.application.deployment', [
            'application' => $application,
            'activity' => $activity,
            'deployment_uuid' => $deployment_uuid,
        ]);
    }
}
