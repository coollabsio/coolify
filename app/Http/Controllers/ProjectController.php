<?php

namespace App\Http\Controllers;

use Spatie\Activitylog\Models\Activity;

class ProjectController extends Controller
{
    public function environments()
    {
        $project = session('currentTeam')->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('home');
        }
        $project->load(['environments']);
        if (count($project->environments) == 1) {
            return redirect()->route('project.resources', ['project_uuid' => $project->uuid, 'environment_name' => $project->environments->first()->name]);
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

    public function application_configuration()
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
        return view('project.applications.configuration', ['application' => $application]);
    }
    public function application_deployments()
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
        return view('project.applications.deployments', ['application' => $application, 'deployments' => $application->deployments()]);
    }

    public function application_deployment()
    {
        $deployment_uuid = request()->route('deployment_uuid');

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
        $activity = Activity::where('properties->deployment_uuid', '=', $deployment_uuid)->first();

        return view('project.applications.deployment', [
            'application' => $application,
            'activity' => $activity,
            'deployment_uuid' => $deployment_uuid,
        ]);
    }
}
