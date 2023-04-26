<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/



Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        $projects = session('currentTeam')->load(['projects'])->projects;
        $servers = session('currentTeam')->load(['servers'])->servers;
        return view('dashboard', [
            'servers' => $servers->sortBy('name'),
            'projects' => $projects->sortBy('name')
        ]);
    })->name('dashboard');

    Route::get('/profile', function () {
        return view('profile');
    })->name('profile');

    Route::get('/settings', function () {
        $isRoot = auth()->user()->isRoot();
        if ($isRoot) {
            $settings = InstanceSettings::find(0);
            return view('settings', [
                'settings' => $settings
            ]);
        } else {
            return redirect()->route('dashboard');
        }
    })->name('settings');

    Route::get('/update', function () {
        return view('update');
    })->name('update');

    Route::get('/demo', function () {
        return view('demo');
    })->name('demo');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/server/new', fn () => view('server.new'))->name('server.new');
    Route::get('/server/{server_uuid}', function () {
        $server = session('currentTeam')->load(['servers'])->servers->firstWhere('uuid', request()->server_uuid);
        if (!$server) {
            abort(404);
        }
        return view('server.show', [
            'server_id' => $server->id,
        ]);
    })->name('server.show');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/project/new', fn () => view('project.new', ['type' => 'project']))->name('project.new');
    Route::get(
        '/project/{project_uuid}',
        [ProjectController::class, 'environments']
    )->name('project.environments');

    Route::get(
        '/project/{project_uuid}/{environment_name}/new',
        [ProjectController::class, 'resources_new']
    )->name('project.resources.new');

    Route::get(
        '/project/{project_uuid}/{environment_name}',
        [ProjectController::class, 'resources']
    )->name('project.resources');

    Route::get(
        '/project/{project_uuid}/{environment_name}/application/{application_uuid}',
        [ApplicationController::class, 'configuration']
    )->name('project.application.configuration');

    Route::get(
        '/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment',
        [ApplicationController::class, 'deployments']
    )->name('project.application.deployments');

    Route::get(
        '/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment/{deployment_uuid}',
        [ApplicationController::class, 'deployment']
    )->name('project.application.deployment');
});
