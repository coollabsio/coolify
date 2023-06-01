<?php

namespace App\Http\Controllers;

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ApplicationController extends Controller
{
    public function configuration()
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
    public function deployments()
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
        return view('project.application.deployments', ['application' => $application]);
    }

    public function deployment()
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
        $activity = Activity::where('properties->type_uuid', '=', $deployment_uuid)->first();
        if (!$activity) {
            return redirect()->route('project.application.deployments', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'application_uuid' => $application->uuid,
            ]);
        }
        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deployment_uuid)->first();
        if (!$deployment) {
            return redirect()->route('project.application.deployments', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'application_uuid' => $application->uuid,
            ]);
        }
        return view('project.application.deployment', [
            'application' => $application,
            'activity' => $activity,
            'deployment' => $deployment,
            'deployment_uuid' => $deployment_uuid,
        ]);
    }
}
