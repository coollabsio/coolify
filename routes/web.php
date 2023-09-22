<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\MagicController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ServerController;
use App\Http\Livewire\Boarding\Index as BoardingIndex;
use App\Http\Livewire\Project\Service\Index as ServiceIndex;
use App\Http\Livewire\Project\Service\Show as ServiceShow;
use App\Http\Livewire\Dashboard;
use App\Http\Livewire\Server\All;
use App\Http\Livewire\Server\Show;
use App\Http\Livewire\Waitlist\Index as WaitlistIndex;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

Route::post('/forgot-password', function (Request $request) {
    if (is_transactional_emails_active()) {
        $arrayOfRequest = $request->only(Fortify::email());
        $request->merge([
            'email' => Str::lower($arrayOfRequest['email']),
        ]);
        $type = set_transanctional_email_settings();
        if (!$type) {
            return response()->json(['message' => 'Transactional emails are not active'], 400);
        }
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
Route::get('/waitlist', WaitlistIndex::class)->name('waitlist.index');
Route::middleware(['throttle:login'])->group(function () {
    Route::get('/auth/link', [Controller::class, 'link'])->name('auth.link');
});
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

    // Applications
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}', [ApplicationController::class, 'configuration'])->name('project.application.configuration');
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment', [ApplicationController::class, 'deployments'])->name('project.application.deployments');
    Route::get(
        '/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment/{deployment_uuid}',
        [ApplicationController::class, 'deployment']
    )->name('project.application.deployment');

    // Databases
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}', [DatabaseController::class, 'configuration'])->name('project.database.configuration');
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}/backups', [DatabaseController::class, 'backups'])->name('project.database.backups.all');
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}/backups/{backup_uuid}', [DatabaseController::class, 'executions'])->name('project.database.backups.executions');

    // Services
    Route::get('/project/{project_uuid}/{environment_name}/service/{service_uuid}', ServiceIndex::class)->name('project.service');
    Route::get('/project/{project_uuid}/{environment_name}/service/{service_uuid}/{service_name}', ServiceShow::class)->name('project.service.show');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/servers', All::class)->name('server.all');
    Route::get('/server/new', [ServerController::class, 'new_server'])->name('server.create');
    Route::get('/server/{server_uuid}', Show::class)->name('server.show');
    Route::get('/server/{server_uuid}/proxy', fn () => view('server.proxy', [
        'server' => Server::ownedByCurrentTeam(['name', 'proxy'])->whereUuid(request()->server_uuid)->firstOrFail(),
    ]))->name('server.proxy');
    Route::get('/server/{server_uuid}/private-key', fn () => view('server.private-key', [
        'server' => Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail(),
        'privateKeys' => PrivateKey::ownedByCurrentTeam()->get(),
    ]))->name('server.private-key');
    Route::get('/server/{server_uuid}/destinations', fn () => view('server.destinations', [
        'server' => Server::ownedByCurrentTeam(['name', 'proxy'])->whereUuid(request()->server_uuid)->firstOrFail()
    ]))->name('server.destinations');
});


Route::middleware(['auth'])->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/boarding', BoardingIndex::class)->name('boarding');
    Route::middleware(['throttle:force-password-reset'])->group(function () {
        Route::get('/force-password-reset', [Controller::class, 'force_passoword_reset'])->name('auth.force-password-reset');
    });
    Route::get('/subscription', [Controller::class, 'subscription'])->name('subscription.index');
    // Route::get('/help', Help::class)->name('help');
    Route::get('/settings', [Controller::class, 'settings'])->name('settings.configuration');
    Route::get('/settings/license', [Controller::class, 'license'])->name('settings.license');
    Route::get('/profile', fn () => view('profile', ['request' => request()]))->name('profile');
    Route::get('/team', [Controller::class, 'team'])->name('team.index');
    Route::get('/team/new', fn () => view('team.create'))->name('team.create');
    Route::get('/team/notifications', fn () => view('team.notifications'))->name('team.notifications');
    Route::get('/team/storages', [Controller::class, 'storages'])->name('team.storages.all');
    Route::get('/team/storages/new', fn () => view('team.storages.create'))->name('team.storages.new');
    Route::get('/team/storages/{storage_uuid}', [Controller::class, 'storages_show'])->name('team.storages.show');
    Route::get('/team/members', [Controller::class, 'members'])->name('team.members');
    Route::get('/command-center', fn () => view('command-center', ['servers' => Server::isReachable()->get()]))->name('command-center');
    Route::get('/invitations/{uuid}', [Controller::class, 'acceptInvitation'])->name('team.invitation.accept');
    Route::get('/invitations/{uuid}/revoke', [Controller::class, 'revokeInvitation'])->name('team.invitation.revoke');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/security', fn () => view('security.index'))->name('security.index');
    Route::get('/security/private-key', fn () => view('security.private-key.index', [
        'privateKeys' => PrivateKey::ownedByCurrentTeam(['name', 'uuid', 'is_git_related'])->where('is_git_related', false)->get()
    ]))->name('security.private-key.index');
    Route::get('/security/private-key/new', fn () => view('security.private-key.new'))->name('security.private-key.new');
    Route::get('/security/private-key/{private_key_uuid}', fn () => view('security.private-key.show', [
        'private_key' => PrivateKey::ownedByCurrentTeam(['name', 'description', 'private_key', 'is_git_related'])->whereUuid(request()->private_key_uuid)->firstOrFail()
    ]))->name('security.private-key.show');
});


Route::middleware(['auth'])->group(function () {
    Route::get('/source/new', fn () => view('source.new'))->name('source.new');
    Route::get('/sources', function () {
        $sources = currentTeam()->sources();
        return view('source.all', [
            'sources' => $sources,
        ]);
    })->name('source.all');
    Route::get('/source/github/{github_app_uuid}', function (Request $request) {
        $github_app = GithubApp::where('uuid', request()->github_app_uuid)->first();
        if (!$github_app) {
            abort(404);
        }
        $github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
        $settings = InstanceSettings::get();
        $name = Str::of(Str::kebab($github_app->name));
        if ($settings->public_ipv4) {
            $ipv4 = 'http://' . $settings->public_ipv4 . ':' . config('app.port');
        }
        if ($settings->public_ipv6) {
            $ipv6 = 'http://' . $settings->public_ipv6 . ':' . config('app.port');
        }
        if ($github_app->installation_id && session('from')) {
            $source_id = data_get(session('from'), 'source_id');
            if (!$source_id || $github_app->id !== $source_id) {
                session()->forget('from');
            } else {
                $parameters = data_get(session('from'), 'parameters');
                $back = data_get(session('from'), 'back');
                $environment_name = data_get($parameters, 'environment_name');
                $project_uuid = data_get($parameters, 'project_uuid');
                $type = data_get($parameters, 'type');
                $destination = data_get($parameters, 'destination');
                session()->forget('from');
                return redirect()->route($back, [
                    'environment_name' => $environment_name,
                    'project_uuid' => $project_uuid,
                    'type' => $type,
                    'destination' => $destination,
                ]);
            }
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
        $servers = Server::isUsable()->get();
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
