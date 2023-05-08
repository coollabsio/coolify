<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Http\Controllers\ServerController;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Http\Request;
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
        $id = session('currentTeam')->id;
        $projects = Project::where('team_id', $id)->get();
        $servers = Server::where('team_id', $id)->get();
        $destinations = $servers->map(function ($server) {
            return $server->standaloneDockers->merge($server->swarmDockers);
        })->flatten();
        $private_keys = PrivateKey::where('team_id', $id)->get();
        $github_apps = GithubApp::private();

        return view('dashboard', [
            'servers' => $servers->sortBy('name'),
            'projects' => $projects->sortBy('name'),
            'destinations' => $destinations->sortBy('name'),
            'private_keys' => $private_keys->sortBy('name'),
            'github_apps' => $github_apps->sortBy('name'),
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

    Route::get('/command-center', function () {
        return view('command-center');
    })->name('command-center');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/private-key/new', fn () => view('private-key.new'))->name('private-key.new');
    Route::get('/private-key/{private_key_uuid}', function () {
        $private_key = PrivateKey::where('uuid', request()->private_key_uuid)->first();
        return view('private-key.show', [
            'private_key' => $private_key,
        ]);
    })->name('private-key.show');
});
Route::middleware(['auth'])->group(function () {
    Route::get('/source/new', fn () => view('source.new'))->name('source.new');
    Route::get('/source/github/{github_app_uuid}', function (Request $request) {
        return view('source.github.show', ['host' => $request->schemeAndHttpHost()]);
    })->name('source.github.show');
});
Route::middleware(['auth'])->group(function () {
    Route::get('/server/new', fn () => view('server.new'))->name('server.new');
    Route::get('/server/{server_uuid}', function () {
        $server = session('currentTeam')->load(['servers'])->servers->firstWhere('uuid', request()->server_uuid);
        if (!$server) {
            abort(404);
        }
        return view('server.show', [
            'server' => $server,
        ]);
    })->name('server.show');
    Route::get('/server/{server_uuid}/private-key', function () {
        return view('server.private-key');
    })->name('server.private-key');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/destination/new', function () {
        $servers = Server::validated();
        return view('destination.new', [
            "servers" => $servers,
        ]);
    })->name('destination.new');
    Route::get('/destination/{destination_uuid}', function () {
        $standalone_dockers = StandaloneDocker::where('uuid', request()->destination_uuid)->first();
        $swarm_dockers = SwarmDocker::where('uuid', request()->destination_uuid)->first();
        if (!$standalone_dockers && !$swarm_dockers) {
            abort(404);
        }
        $destination = $standalone_dockers ? $standalone_dockers : $swarm_dockers;
        return view('destination.show', [
            'destination' => $destination->load(['server']),
        ]);
    })->name('destination.show');
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
