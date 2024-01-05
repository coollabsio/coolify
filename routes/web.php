<?php

use App\Events\TestEvent;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\MagicController;
use App\Http\Controllers\ProjectController;
use App\Livewire\Project\Application\Configuration as ApplicationConfiguration;
use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\Project\Service\Index as ServiceIndex;
use App\Livewire\Project\Service\Show as ServiceShow;
use App\Livewire\Dev\Compose as Compose;
use App\Livewire\Dashboard;
use App\Livewire\Project\CloneProject;
use App\Livewire\Project\EnvironmentEdit;
use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Project\Shared\Logs;
use App\Livewire\Project\Shared\ScheduledTask\Show as ScheduledTaskShow;
use App\Livewire\Security\ApiTokens;
use App\Livewire\Server\All;
use App\Livewire\Server\Create;
use App\Livewire\Server\Destination\Show as DestinationShow;
use App\Livewire\Server\LogDrains;
use App\Livewire\Server\PrivateKey\Show as PrivateKeyShow;
use App\Livewire\Server\Proxy\Show as ProxyShow;
use App\Livewire\Server\Proxy\Logs as ProxyLogs;
use App\Livewire\Server\Show;
use App\Livewire\Source\Github\Change as GitHubChange;
use App\Livewire\Subscription\Show as SubscriptionShow;
use App\Livewire\Waitlist\Index as WaitlistIndex;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

if (isDev()) {
    Route::get('/dev/compose', Compose::class)->name('dev.compose');
}

Route::get('/api/v1/test/realtime', function () {
    if (auth()->user()?->currentTeam()->id !== 0) {
        return redirect(RouteServiceProvider::HOME);
    }
    TestEvent::dispatch('asd');
    return 'Look at your other tab.';
})->middleware('auth');


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

Route::get('/verify', function () {
    return view('auth.verify-email');
})->middleware('auth')->name('verify.email');

Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill();
    send_internal_notification("User {$request->user()->name} verified their email address.");
    return redirect(RouteServiceProvider::HOME);
})->middleware(['auth'])->name('verify.verify');

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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/projects', [ProjectController::class, 'all'])->name('projects');
    Route::get('/project/{project_uuid}', [ProjectController::class, 'show'])->name('project.show');
    Route::get('/project/{project_uuid}/edit', [ProjectController::class, 'edit'])->name('project.edit');
    Route::get('/project/{project_uuid}/{environment_name}/clone', CloneProject::class)->name('project.clone');

    Route::get('/project/{project_uuid}/{environment_name}/new', [ProjectController::class, 'new'])->name('project.resources.new');
    Route::get('/project/{project_uuid}/{environment_name}', [ProjectController::class, 'resources'])->name('project.resources');
    Route::get('/project/{project_uuid}/{environment_name}/edit', EnvironmentEdit::class)->name('project.environment.edit');

    // Applications
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}', ApplicationConfiguration::class)->name('project.application.configuration');

    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment', [ApplicationController::class, 'deployments'])->name('project.application.deployments');
    Route::get(
        '/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment/{deployment_uuid}',
        [ApplicationController::class, 'deployment']
    )->name('project.application.deployment');

    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/logs', Logs::class)->name('project.application.logs');
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/command', ExecuteContainerCommand::class)->name('project.application.command');
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/tasks/{task_uuid}', ScheduledTaskShow::class)->name('project.application.scheduled-tasks');


    // Databases
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}', [DatabaseController::class, 'configuration'])->name('project.database.configuration');
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}/backups', [DatabaseController::class, 'backups'])->name('project.database.backups.all');
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}/backups/{backup_uuid}', [DatabaseController::class, 'executions'])->name('project.database.backups.executions');
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}/logs', Logs::class)->name('project.database.logs');
    Route::get('/project/{project_uuid}/{environment_name}/database/{database_uuid}/command', ExecuteContainerCommand::class)->name('project.database.command');


    // Services
    Route::get('/project/{project_uuid}/{environment_name}/service/{service_uuid}', ServiceIndex::class)->name('project.service.configuration');
    Route::get('/project/{project_uuid}/{environment_name}/service/{service_uuid}/{service_name}', ServiceShow::class)->name('project.service.show');
    Route::get('/project/{project_uuid}/{environment_name}/service/{service_uuid}/command', ExecuteContainerCommand::class)->name('project.service.command');
    Route::get('/project/{project_uuid}/{environment_name}/service/{service_uuid}/tasks/{task_uuid}', ScheduledTaskShow::class)->name('project.service.scheduled-tasks');

});

Route::middleware(['auth'])->group(function () {
    Route::get('/servers', All::class)->name('server.all');
    Route::get('/server/new', Create::class)->name('server.create');
    Route::get('/server/{server_uuid}', Show::class)->name('server.show');
    Route::get('/server/{server_uuid}/proxy', ProxyShow::class)->name('server.proxy');
    Route::get('/server/{server_uuid}/proxy/logs', ProxyLogs::class)->name('server.proxy.logs');
    Route::get('/server/{server_uuid}/private-key', PrivateKeyShow::class)->name('server.private-key');
    Route::get('/server/{server_uuid}/destinations', DestinationShow::class)->name('server.destinations');
    Route::get('/server/{server_uuid}/log-drains', LogDrains::class)->name('server.log-drains');
});


Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/boarding', BoardingIndex::class)->name('boarding');
    Route::middleware(['throttle:force-password-reset'])->group(function () {
        Route::get('/force-password-reset', [Controller::class, 'force_passoword_reset'])->name('auth.force-password-reset');
    });
    Route::get('/subscription', SubscriptionShow::class)->name('subscription.index');
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
        'privateKeys' => PrivateKey::ownedByCurrentTeam(['name', 'uuid', 'is_git_related'])->get()
    ]))->name('security.private-key.index');
    Route::get('/security/private-key/new', fn () => view('security.private-key.new'))->name('security.private-key.new');
    Route::get('/security/private-key/{private_key_uuid}', fn () => view('security.private-key.show', [
        'private_key' => PrivateKey::ownedByCurrentTeam(['name', 'description', 'private_key', 'is_git_related'])->whereUuid(request()->private_key_uuid)->firstOrFail()
    ]))->name('security.private-key.show');
    Route::get('/security/api-tokens', ApiTokens::class)->name('security.api-tokens');
});


Route::middleware(['auth'])->group(function () {
    Route::get('/source/new', fn () => view('source.new'))->name('source.new');
    Route::get('/sources', function () {
        $sources = currentTeam()->sources();
        return view('source.all', [
            'sources' => $sources,
        ]);
    })->name('source.all');
    Route::get('/source/github/{github_app_uuid}', GitHubChange::class)->name('source.github.show');
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

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }
    return redirect()->route('login');
})->where('any', '.*');
