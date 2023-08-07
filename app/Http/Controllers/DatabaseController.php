<?php

namespace App\Http\Controllers;

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class DatabaseController extends Controller
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
        $database = $environment->databases->where('uuid', request()->route('database_uuid'))->first();
        if (!$database) {
            return redirect()->route('dashboard');
        }
        return view('project.database.configuration', ['database' => $database]);
    }
}