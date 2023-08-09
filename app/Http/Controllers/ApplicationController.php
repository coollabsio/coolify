<?php

namespace App\Http\Controllers;

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

class ApplicationController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

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
        ['deployments' => $deployments, 'count' => $count] = $application->deployments(0, 8);
        return view('project.application.deployments', ['application' => $application, 'deployments' => $deployments, 'deployments_count' => $count]);
    }

    public function deployment()
    {
        $deploymentUuid = request()->route('deployment_uuid');

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
        // $activity = Activity::where('properties->type_uuid', '=', $deploymentUuid)->first();
        // if (!$activity) {
        //     return redirect()->route('project.application.deployments', [
        //         'project_uuid' => $project->uuid,
        //         'environment_name' => $environment->name,
        //         'application_uuid' => $application->uuid,
        //     ]);
        // }
        $application_deployment_queue = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)->first();
        if (!$application_deployment_queue) {
            return redirect()->route('project.application.deployments', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'application_uuid' => $application->uuid,
            ]);
        }
        return view('project.application.deployment', [
            'application' => $application,
            // 'activity' => $activity,
            'application_deployment_queue' => $application_deployment_queue,
            'deployment_uuid' => $deploymentUuid,
        ]);
    }
}
