<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ApplicationController extends Controller
{
    public function configuration()
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
        return view('project.application.configuration', ['application' => $application]);
    }
    public function deployments()
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
        return view('project.application.deployments', ['application' => $application, 'deployments' => $application->deployments()]);
    }

    public function deployment()
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

        return view('project.application.deployment', [
            'application' => $application,
            'activity' => $activity,
            'deployment_uuid' => $deployment_uuid,
        ]);
    }
}
