<?php

use App\Http\Controllers\Controller;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\ApiAllowed;
use App\Livewire\Admin\Index as AdminIndex;
use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\Dashboard;
use App\Livewire\Destination\Index as DestinationIndex;
use App\Livewire\Destination\Show as DestinationShow;
use App\Livewire\ForcePasswordReset;
use App\Livewire\Notifications\Discord as NotificationDiscord;
use App\Livewire\Notifications\Email as NotificationEmail;
use App\Livewire\Notifications\Pushover as NotificationPushover;
use App\Livewire\Notifications\Slack as NotificationSlack;
use App\Livewire\Notifications\Telegram as NotificationTelegram;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Livewire\Project\Application\Configuration as ApplicationConfiguration;
use App\Livewire\Project\Application\Deployment\Index as DeploymentIndex;
use App\Livewire\Project\Application\Deployment\Show as DeploymentShow;
use App\Livewire\Project\CloneMe as ProjectCloneMe;
use App\Livewire\Project\Database\Backup\Execution as DatabaseBackupExecution;
use App\Livewire\Project\Database\Backup\Index as DatabaseBackupIndex;
use App\Livewire\Project\Database\Configuration as DatabaseConfiguration;
use App\Livewire\Project\Edit as ProjectEdit;
use App\Livewire\Project\EnvironmentEdit;
use App\Livewire\Project\Index as ProjectIndex;
use App\Livewire\Project\Resource\Create as ResourceCreate;
use App\Livewire\Project\Resource\Index as ResourceIndex;
use App\Livewire\Project\Service\Configuration as ServiceConfiguration;
use App\Livewire\Project\Service\Index as ServiceIndex;
use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Project\Shared\Logs;
use App\Livewire\Project\Shared\ScheduledTask\Show as ScheduledTaskShow;
use App\Livewire\Project\Show as ProjectShow;
use App\Livewire\Security\ApiTokens;
use App\Livewire\Security\PrivateKey\Index as SecurityPrivateKeyIndex;
use App\Livewire\Security\PrivateKey\Show as SecurityPrivateKeyShow;
use App\Livewire\Server\Advanced as ServerAdvanced;
use App\Livewire\Server\Charts as ServerCharts;
use App\Livewire\Server\CloudflareTunnels;
use App\Livewire\Server\Delete as DeleteServer;
use App\Livewire\Server\Destinations as ServerDestinations;
use App\Livewire\Server\DockerCleanup;
use App\Livewire\Server\Index as ServerIndex;
use App\Livewire\Server\LogDrains;
use App\Livewire\Server\PrivateKey\Show as PrivateKeyShow;
use App\Livewire\Server\Proxy\DynamicConfigurations as ProxyDynamicConfigurations;
use App\Livewire\Server\Proxy\Logs as ProxyLogs;
use App\Livewire\Server\Proxy\Show as ProxyShow;
use App\Livewire\Server\Resources as ResourcesShow;
use App\Livewire\Server\Show as ServerShow;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\SettingsBackup;
use App\Livewire\SettingsEmail;
use App\Livewire\SettingsOauth;
use App\Livewire\SharedVariables\Environment\Index as EnvironmentSharedVariablesIndex;
use App\Livewire\SharedVariables\Environment\Show as EnvironmentSharedVariablesShow;
use App\Livewire\SharedVariables\Index as SharedVariablesIndex;
use App\Livewire\SharedVariables\Project\Index as ProjectSharedVariablesIndex;
use App\Livewire\SharedVariables\Project\Show as ProjectSharedVariablesShow;
use App\Livewire\SharedVariables\Team\Index as TeamSharedVariablesIndex;
use App\Livewire\Source\Github\Change as GitHubChange;
use App\Livewire\Storage\Index as StorageIndex;
use App\Livewire\Storage\Show as StorageShow;
use App\Livewire\Subscription\Index as SubscriptionIndex;
use App\Livewire\Subscription\Show as SubscriptionShow;
use App\Livewire\Tags\Show as TagsShow;
use App\Livewire\Team\AdminView as TeamAdminView;
use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Team\Member\Index as TeamMemberIndex;
use App\Livewire\Terminal\Index as TerminalIndex;
use App\Models\GitlabApp;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ThreeSidedCube\LaravelRedoc\Http\Controllers\DefinitionController;
use ThreeSidedCube\LaravelRedoc\Http\Controllers\DocumentationController;

Route::group(['middleware' => ['auth:sanctum', ApiAllowed::class]], function () {
    Route::get('/docs/api', DocumentationController::class)->name('redoc.documentation');
    Route::get('/docs/api/definition', DefinitionController::class)->name('redoc.definition');
});

Route::get('/admin', AdminIndex::class)->name('admin.index');

Route::post('/forgot-password', [Controller::class, 'forgot_password'])->name('password.forgot')->middleware('throttle:forgot-password');
Route::get('/realtime', [Controller::class, 'realtime_test'])->middleware('auth');
Route::get('/verify', [Controller::class, 'verify'])->middleware('auth')->name('verify.email');
Route::get('/email/verify/{id}/{hash}', [Controller::class, 'email_verify'])->middleware(['auth'])->name('verify.verify');
Route::middleware(['throttle:login'])->group(function () {
    Route::get('/auth/link', [Controller::class, 'link'])->name('auth.link');
});

Route::get('/auth/{provider}/redirect', [OauthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/{provider}/callback', [OauthController::class, 'callback'])->name('auth.callback');

// Route::prefix('magic')->middleware(['auth'])->group(function () {
//     Route::get('/servers', [MagicController::class, 'servers']);
//     Route::get('/destinations', [MagicController::class, 'destinations']);
//     Route::get('/projects', [MagicController::class, 'projects']);
//     Route::get('/environments', [MagicController::class, 'environments']);
//     Route::get('/project/new', [MagicController::class, 'newProject']);
//     Route::get('/environment/new', [MagicController::class, 'newEnvironment']);
// });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware(['throttle:force-password-reset'])->group(function () {
        Route::get('/force-password-reset', ForcePasswordReset::class)->name('auth.force-password-reset');
    });

    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/onboarding', BoardingIndex::class)->name('onboarding');

    Route::get('/subscription', SubscriptionShow::class)->name('subscription.show');
    Route::get('/subscription/new', SubscriptionIndex::class)->name('subscription.index');

    Route::get('/settings', SettingsIndex::class)->name('settings.index');
    Route::get('/settings/backup', SettingsBackup::class)->name('settings.backup');
    Route::get('/settings/email', SettingsEmail::class)->name('settings.email');
    Route::get('/settings/oauth', SettingsOauth::class)->name('settings.oauth');

    Route::get('/profile', ProfileIndex::class)->name('profile');

    Route::prefix('tags')->group(function () {
        Route::get('/{tagName?}', TagsShow::class)->name('tags.show');
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/email', NotificationEmail::class)->name('notifications.email');
        Route::get('/telegram', NotificationTelegram::class)->name('notifications.telegram');
        Route::get('/discord', NotificationDiscord::class)->name('notifications.discord');
        Route::get('/slack', NotificationSlack::class)->name('notifications.slack');
        Route::get('/pushover', NotificationPushover::class)->name('notifications.pushover');
    });

    Route::prefix('storages')->group(function () {
        Route::get('/', StorageIndex::class)->name('storage.index');
        Route::get('/{storage_uuid}', StorageShow::class)->name('storage.show');
    });
    Route::prefix('shared-variables')->group(function () {
        Route::get('/', SharedVariablesIndex::class)->name('shared-variables.index');
        Route::get('/team', TeamSharedVariablesIndex::class)->name('shared-variables.team.index');
        Route::get('/projects', ProjectSharedVariablesIndex::class)->name('shared-variables.project.index');
        Route::get('/project/{project_uuid}', ProjectSharedVariablesShow::class)->name('shared-variables.project.show');
        Route::get('/environments', EnvironmentSharedVariablesIndex::class)->name('shared-variables.environment.index');
        Route::get('/environments/project/{project_uuid}/environment/{environment_uuid}', EnvironmentSharedVariablesShow::class)->name('shared-variables.environment.show');
    });

    Route::prefix('team')->group(function () {
        Route::get('/', TeamIndex::class)->name('team.index');
        Route::get('/members', TeamMemberIndex::class)->name('team.member.index');
        Route::get('/admin', TeamAdminView::class)->name('team.admin-view');
    });

    Route::get('/terminal', TerminalIndex::class)->name('terminal');
    Route::post('/terminal/auth', function () {
        if (auth()->check()) {
            return response()->json(['authenticated' => true], 200);
        }

        return response()->json(['authenticated' => false], 401);
    })->name('terminal.auth');

    Route::prefix('invitations')->group(function () {
        Route::get('/{uuid}', [Controller::class, 'acceptInvitation'])->name('team.invitation.accept');
        Route::get('/{uuid}/revoke', [Controller::class, 'revoke_invitation'])->name('team.invitation.revoke');
    });

    Route::get('/projects', ProjectIndex::class)->name('project.index');
    Route::prefix('project/{project_uuid}')->group(function () {
        Route::get('/', ProjectShow::class)->name('project.show');
        Route::get('/edit', ProjectEdit::class)->name('project.edit');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}')->group(function () {
        Route::get('/', ResourceIndex::class)->name('project.resource.index');
        Route::get('/clone', ProjectCloneMe::class)->name('project.clone-me');
        Route::get('/new', ResourceCreate::class)->name('project.resource.create');
        Route::get('/edit', EnvironmentEdit::class)->name('project.environment.edit');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}')->group(function () {
        Route::get('/', ApplicationConfiguration::class)->name('project.application.configuration');
        Route::get('/swarm', ApplicationConfiguration::class)->name('project.application.swarm');
        Route::get('/advanced', ApplicationConfiguration::class)->name('project.application.advanced');
        Route::get('/environment-variables', ApplicationConfiguration::class)->name('project.application.environment-variables');
        Route::get('/persistent-storage', ApplicationConfiguration::class)->name('project.application.persistent-storage');
        Route::get('/source', ApplicationConfiguration::class)->name('project.application.source');
        Route::get('/servers', ApplicationConfiguration::class)->name('project.application.servers');
        Route::get('/scheduled-tasks', ApplicationConfiguration::class)->name('project.application.scheduled-tasks.show');
        Route::get('/webhooks', ApplicationConfiguration::class)->name('project.application.webhooks');
        Route::get('/preview-deployments', ApplicationConfiguration::class)->name('project.application.preview-deployments');
        Route::get('/healthcheck', ApplicationConfiguration::class)->name('project.application.healthcheck');
        Route::get('/rollback', ApplicationConfiguration::class)->name('project.application.rollback');
        Route::get('/resource-limits', ApplicationConfiguration::class)->name('project.application.resource-limits');
        Route::get('/resource-operations', ApplicationConfiguration::class)->name('project.application.resource-operations');
        Route::get('/metrics', ApplicationConfiguration::class)->name('project.application.metrics');
        Route::get('/tags', ApplicationConfiguration::class)->name('project.application.tags');
        Route::get('/danger', ApplicationConfiguration::class)->name('project.application.danger');

        Route::get('/deployment', DeploymentIndex::class)->name('project.application.deployment.index');
        Route::get('/deployment/{deployment_uuid}', DeploymentShow::class)->name('project.application.deployment.show');
        Route::get('/logs', Logs::class)->name('project.application.logs');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.application.command');
        Route::get('/tasks/{task_uuid}', ScheduledTaskShow::class)->name('project.application.scheduled-tasks');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}')->group(function () {
        Route::get('/', DatabaseConfiguration::class)->name('project.database.configuration');
        Route::get('/environment-variables', DatabaseConfiguration::class)->name('project.database.environment-variables');
        Route::get('/servers', DatabaseConfiguration::class)->name('project.database.servers');
        Route::get('/import-backups', DatabaseConfiguration::class)->name('project.database.import-backups');
        Route::get('/persistent-storage', DatabaseConfiguration::class)->name('project.database.persistent-storage');
        Route::get('/webhooks', DatabaseConfiguration::class)->name('project.database.webhooks');
        Route::get('/resource-limits', DatabaseConfiguration::class)->name('project.database.resource-limits');
        Route::get('/resource-operations', DatabaseConfiguration::class)->name('project.database.resource-operations');
        Route::get('/metrics', DatabaseConfiguration::class)->name('project.database.metrics');
        Route::get('/tags', DatabaseConfiguration::class)->name('project.database.tags');
        Route::get('/danger', DatabaseConfiguration::class)->name('project.database.danger');

        Route::get('/logs', Logs::class)->name('project.database.logs');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.database.command');
        Route::get('/backups', DatabaseBackupIndex::class)->name('project.database.backup.index');
        Route::get('/backups/{backup_uuid}', DatabaseBackupExecution::class)->name('project.database.backup.execution');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}')->group(function () {
        Route::get('/', ServiceConfiguration::class)->name('project.service.configuration');
        Route::get('/logs', Logs::class)->name('project.service.logs');
        Route::get('/environment-variables', ServiceConfiguration::class)->name('project.service.environment-variables');
        Route::get('/storages', ServiceConfiguration::class)->name('project.service.storages');
        Route::get('/scheduled-tasks', ServiceConfiguration::class)->name('project.service.scheduled-tasks.show');
        Route::get('/webhooks', ServiceConfiguration::class)->name('project.service.webhooks');
        Route::get('/resource-operations', ServiceConfiguration::class)->name('project.service.resource-operations');
        Route::get('/tags', ServiceConfiguration::class)->name('project.service.tags');
        Route::get('/danger', ServiceConfiguration::class)->name('project.service.danger');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.service.command');
        Route::get('/{stack_service_uuid}', ServiceIndex::class)->name('project.service.index');
        Route::get('/tasks/{task_uuid}', ScheduledTaskShow::class)->name('project.service.scheduled-tasks');
    });

    Route::get('/servers', ServerIndex::class)->name('server.index');
    // Route::get('/server/new', ServerCreate::class)->name('server.create');

    Route::prefix('server/{server_uuid}')->group(function () {
        Route::get('/', ServerShow::class)->name('server.show');
        Route::get('/advanced', ServerAdvanced::class)->name('server.advanced');
        Route::get('/private-key', PrivateKeyShow::class)->name('server.private-key');
        Route::get('/resources', ResourcesShow::class)->name('server.resources');
        Route::get('/cloudflare-tunnels', CloudflareTunnels::class)->name('server.cloudflare-tunnels');
        Route::get('/destinations', ServerDestinations::class)->name('server.destinations');
        Route::get('/log-drains', LogDrains::class)->name('server.log-drains');
        Route::get('/metrics', ServerCharts::class)->name('server.charts');
        Route::get('/danger', DeleteServer::class)->name('server.delete');
        Route::get('/proxy', ProxyShow::class)->name('server.proxy');
        Route::get('/proxy/dynamic', ProxyDynamicConfigurations::class)->name('server.proxy.dynamic-confs');
        Route::get('/proxy/logs', ProxyLogs::class)->name('server.proxy.logs');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('server.command');
        Route::get('/docker-cleanup', DockerCleanup::class)->name('server.docker-cleanup');
    });
    Route::get('/destinations', DestinationIndex::class)->name('destination.index');
    Route::get('/destination/{destination_uuid}', DestinationShow::class)->name('destination.show');

    // Route::get('/security', fn () => view('security.index'))->name('security.index');
    Route::get('/security/private-key', SecurityPrivateKeyIndex::class)->name('security.private-key.index');
    // Route::get('/security/private-key/new', SecurityPrivateKeyCreate::class)->name('security.private-key.create');
    Route::get('/security/private-key/{private_key_uuid}', SecurityPrivateKeyShow::class)->name('security.private-key.show');

    Route::get('/security/api-tokens', ApiTokens::class)->name('security.api-tokens');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/sources', function () {
        $sources = currentTeam()->sources();

        return view('source.all', [
            'sources' => $sources,
        ]);
    })->name('source.all');
    Route::get('/source/github/{github_app_uuid}', GitHubChange::class)->name('source.github.show');
    Route::get('/source/gitlab/{gitlab_app_uuid}', function (Request $request) {
        $gitlab_app = GitlabApp::ownedByCurrentTeam()->where('uuid', request()->gitlab_app_uuid)->firstOrFail();

        return view('source.gitlab.show', [
            'gitlab_app' => $gitlab_app,
        ]);
    })->name('source.gitlab.show');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/upload/backup/{databaseUuid}', [UploadController::class, 'upload'])->name('upload.backup');
    Route::get('/download/backup/{executionId}', function () {
        try {
            $team = auth()->user()->currentTeam();
            if (is_null($team)) {
                return response()->json(['message' => 'Team not found.'], 404);
            }
            $exeuctionId = request()->route('executionId');
            $execution = ScheduledDatabaseBackupExecution::where('id', $exeuctionId)->firstOrFail();
            $execution_team_id = $execution->scheduledDatabaseBackup->database->team()?->id;
            if ($team->id !== 0) {
                if (is_null($execution_team_id)) {
                    return response()->json(['message' => 'Team not found.'], 404);
                }
                if ($team->id !== $execution_team_id) {
                    return response()->json(['message' => 'Permission denied.'], 403);
                }
                if (is_null($execution)) {
                    return response()->json(['message' => 'Backup not found.'], 404);
                }
            }
            $filename = data_get($execution, 'filename');
            if ($execution->scheduledDatabaseBackup->database->getMorphClass() === \App\Models\ServiceDatabase::class) {
                $server = $execution->scheduledDatabaseBackup->database->service->destination->server;
            } else {
                $server = $execution->scheduledDatabaseBackup->database->destination->server;
            }

            $privateKeyLocation = $server->privateKey->getKeyLocation();
            $disk = Storage::build([
                'driver' => 'sftp',
                'host' => $server->ip,
                'port' => (int) $server->port,
                'username' => $server->user,
                'privateKey' => $privateKeyLocation,
                'root' => '/',
            ]);
            if (! $disk->exists($filename)) {
                return response()->json(['message' => 'Backup not found.'], 404);
            }

            return new StreamedResponse(function () use ($disk, $filename) {
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $stream = $disk->readStream($filename);
                if ($stream === false || is_null($stream)) {
                    abort(500, 'Failed to open stream for the requested file.');
                }
                while (! feof($stream)) {
                    echo fread($stream, 2048);
                    flush();
                }

                fclose($stream);
            }, 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.basename($filename).'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    })->name('download.backup');

});

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }

    return redirect()->route('login');
})->where('any', '.*');
