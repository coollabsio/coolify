<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MagicController;
use App\Http\Controllers\ProjectController;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

Route::post('/forgot-password', function (Request $request) {
    if (is_transactional_emails_active()) {
        set_transanctional_email_settings();
        $request->validate([Fortify::email() => 'required|email']);
        $status = Password::broker(config('fortify.passwords'))->sendResetLink(
            $request->only(Fortify::email())
        );
        if ($status == Password::RESET_LINK_SENT) {
            return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status]);
        }
        if ($status == Password::RESET_THROTTLED) {
            return response('Already requested a password reset in the past minutes.', 400);
        }
        return app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
    }
    return response()->json(['message' => 'Transactional emails are not active'], 400);
})->name('password.forgot');
Route::prefix('magic')->middleware(['auth'])->group(function () {
    Route::get('/servers', [MagicController::class, 'servers']);
    Route::get('/destinations', [MagicController::class, 'destinations']);
    Route::get('/projects', [MagicController::class, 'projects']);
    Route::get('/environments', [MagicController::class, 'environments']);
    Route::get('/project/new', [MagicController::class, 'newProject']);
    Route::get('/environment/new', [MagicController::class, 'newEnvironment']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('/projects', [ProjectController::class, 'all'])->name('projects');
    Route::get('/project/{project_uuid}/edit', [ProjectController::class, 'edit'])->name('project.edit');
    Route::get('/project/{project_uuid}', [ProjectController::class, 'show'])->name('project.show');
    Route::get('/project/{project_uuid}/{environment_name}/new', [ProjectController::class, 'new'])->name('project.resources.new');
    Route::get('/project/{project_uuid}/{environment_name}', [ProjectController::class, 'resources'])->name('project.resources');
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}', [ApplicationController::class, 'configuration'])->name('project.application.configuration');

    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment',        [ApplicationController::class, 'deployments'])->name('project.application.deployments');

    Route::get(
        '/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment/{deployment_uuid}',
        [ApplicationController::class, 'deployment']
    )->name('project.application.deployment');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/servers', fn () => view('server.all', [
        'servers' => Server::ownedByCurrentTeam()->get()
    ]))->name('server.all');
    Route::get('/server/new', fn () => view('server.create', [
        'private_keys' => PrivateKey::ownedByCurrentTeam()->get(),
    ]))->name('server.create');
    Route::get('/server/{server_uuid}', fn () => view('server.show', [
        'server' => Server::ownedByCurrentTeam(['name', 'description', 'ip', 'port', 'user'])->whereUuid(request()->server_uuid)->firstOrFail(),
    ]))->name('server.show');
    Route::get('/server/{server_uuid}/proxy', fn () => view('server.proxy', [
        'server' => Server::ownedByCurrentTeam(['name', 'proxy'])->whereUuid(request()->server_uuid)->firstOrFail(),
    ]))->name('server.proxy');
    Route::get('/server/{server_uuid}/private-key', fn () => view('server.private-key', [
        'server' => Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail(),
        'privateKeys' => PrivateKey::ownedByCurrentTeam()->get(),
    ]))->name('server.private-key');
    Route::get('/server/{server_uuid}/destinations', fn () => view('server.destinations', [
        'server' => Server::ownedByCurrentTeam(['name'])->whereUuid(request()->server_uuid)->firstOrFail()
    ]))->name('server.destinations');
});


Route::middleware(['auth'])->group(function () {
    Route::get('/', [Controller::class, 'dashboard'])->name('dashboard');
    Route::get('/settings', [Controller::class, 'settings'])->name('settings.configuration');
    Route::get('/settings/emails', [Controller::class, 'emails'])->name('settings.emails');
    Route::get('/profile', fn () => view('profile', ['request' => request()]))->name('profile');
    Route::get('/team', [Controller::class, 'team'])->name('team.show');
    Route::get('/team/new', fn () => view('team.create'))->name('team.create');
    Route::get('/team/notifications', fn () => view('team.notifications'))->name('team.notifications');
    Route::get('/command-center', fn () => view('command-center', ['servers' => Server::validated()->get()]))->name('command-center');
    Route::get('/invitations/{uuid}', [Controller::class, 'acceptInvitation'])->name('team.invitation.accept');
    Route::get('/invitations/{uuid}/revoke', [Controller::class, 'revokeInvitation'])->name('team.invitation.revoke');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/private-keys', fn () => view('private-key.all', [
        'privateKeys' => PrivateKey::ownedByCurrentTeam(['name', 'uuid', 'is_git_related'])->where('is_git_related', false)->get()
    ]))->name('private-key.all');
    Route::get('/private-key/new', fn () => view('private-key.new'))->name('private-key.new');
    Route::get('/private-key/{private_key_uuid}', fn () => view('private-key.show', [
        'private_key' => PrivateKey::ownedByCurrentTeam(['name', 'description', 'private_key', 'is_git_related'])->whereUuid(request()->private_key_uuid)->firstOrFail()
    ]))->name('private-key.show');
});


Route::middleware(['auth'])->group(function () {
    Route::get('/source/new', fn () => view('source.new'))->name('source.new');
    Route::get('/sources', function () {
        $sources = session('currentTeam')->sources();
        return view('source.all', [
            'sources' => $sources,
        ]);
    })->name('source.all');
    Route::get('/source/github/{github_app_uuid}', function (Request $request) {
        $github_app = GithubApp::where('uuid', request()->github_app_uuid)->first();
        $settings = InstanceSettings::get();
        $name = Str::of(Str::kebab($github_app->name));
        if ($settings->public_ipv4) {
            $ipv4 = 'http://' . $settings->public_ipv4 . ':' . config('app.port');
        }
        if ($settings->public_ipv6) {
            $ipv6 = 'http://' . $settings->public_ipv6 . ':' . config('app.port');
        }
        return view('source.github.show', [
            'github_app' => $github_app,
            'name' => $name,
            'ipv4' => $ipv4 ?? null,
            'ipv6' => $ipv6 ?? null,
            'fqdn' => $settings->fqdn,
        ]);
    })->name('source.github.show');

    Route::get('/source/gitlab/{gitlab_app_uuid}', function (Request $request) {
        $gitlab_app = GitlabApp::where('uuid', request()->gitlab_app_uuid)->first();
        return view('source.gitlab.show', [
            'gitlab_app' => $gitlab_app,
        ]);
    })->name('source.gitlab.show');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/destinations', function () {
        $servers = Server::all();
        $destinations = collect([]);
        foreach ($servers as $server) {
            $destinations = $destinations->merge($server->destinations());
        }
        return view('destination.all', [
            'destinations' => $destinations,
        ]);
    })->name('destination.all');
    Route::get('/destination/new', function () {
        $servers = Server::validated()->get();
        $pre_selected_server_uuid = data_get(request()->query(), 'server');
        if ($pre_selected_server_uuid) {
            $server = $servers->firstWhere('uuid', $pre_selected_server_uuid);
            if ($server) {
                $server_id = $server->id;
            }
        }
        return view('destination.new', [
            "servers" => $servers,
            "server_id" => $server_id ?? null,
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
