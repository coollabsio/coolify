<?php

use App\Http\Controllers\Controller;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\ProfileAvatarController;
use App\Http\Controllers\ProjectIconController;
use App\Http\Controllers\UploadController;
use App\Livewire\Admin\Index as AdminIndex;
use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\Dashboard;
use App\Livewire\Destination\Index as DestinationIndex;
use App\Livewire\Destination\Resources as DestinationResources;
use App\Livewire\Destination\Show as DestinationShow;
use App\Livewire\ForcePasswordReset;
use App\Livewire\Notifications\Discord as NotificationDiscord;
use App\Livewire\Notifications\Email as NotificationEmail;
use App\Livewire\Notifications\Pushover as NotificationPushover;
use App\Livewire\Notifications\Slack as NotificationSlack;
use App\Livewire\Notifications\Telegram as NotificationTelegram;
use App\Livewire\Notifications\Webhook as NotificationWebhook;
use App\Livewire\Profile\Appearance as ProfileAppearance;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Livewire\Project\Application\Backup\Index as ApplicationBackupIndex;
use App\Livewire\Project\Application\Backup\Show as ApplicationBackupShow;
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
use App\Livewire\Project\Service\DatabaseBackups as ServiceDatabaseBackups;
use App\Livewire\Project\Service\Index as ServiceIndex;
use App\Livewire\Project\Service\VolumeBackup\Index;
use App\Livewire\Project\Service\VolumeBackup\Show;
use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Project\Shared\Logs;
use App\Livewire\Project\Show as ProjectShow;
use App\Livewire\Security\ApiTokens;
use App\Livewire\Security\CloudInitScript\Show as SecurityCloudInitScriptShow;
use App\Livewire\Security\CloudInitScripts;
use App\Livewire\Security\CloudProviderToken\Show as SecurityCloudProviderTokenShow;
use App\Livewire\Security\CloudTokens;
use App\Livewire\Security\PrivateKey\Index as SecurityPrivateKeyIndex;
use App\Livewire\Security\PrivateKey\Show as SecurityPrivateKeyShow;
use App\Livewire\Server\Advanced as ServerAdvanced;
use App\Livewire\Server\CaCertificate\Show as CaCertificateShow;
use App\Livewire\Server\Charts as ServerCharts;
use App\Livewire\Server\CloudflareTunnel;
use App\Livewire\Server\CloudProviderToken\Show as CloudProviderTokenShow;
use App\Livewire\Server\CreatePage as ServerCreatePage;
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
use App\Livewire\Server\Security\Patches;
use App\Livewire\Server\Security\TerminalAccess;
use App\Livewire\Server\Sentinel\Logs as SentinelLogs;
use App\Livewire\Server\Sentinel\Show as SentinelShow;
use App\Livewire\Server\Show as ServerShow;
use App\Livewire\Server\Swarm as ServerSwarm;
use App\Livewire\Server\Transfer as ServerTransfer;
use App\Livewire\Server\TransferImport as ServerTransferImport;
use App\Livewire\Settings\Advanced as SettingsAdvanced;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Settings\ScheduledJobs as SettingsScheduledJobs;
use App\Livewire\Settings\Updates as SettingsUpdates;
use App\Livewire\SettingsBackup;
use App\Livewire\SettingsEmail;
use App\Livewire\SettingsOauth;
use App\Livewire\SharedVariables\Environment\Index as EnvironmentSharedVariablesIndex;
use App\Livewire\SharedVariables\Environment\Show as EnvironmentSharedVariablesShow;
use App\Livewire\SharedVariables\Index as SharedVariablesIndex;
use App\Livewire\SharedVariables\Project\Index as ProjectSharedVariablesIndex;
use App\Livewire\SharedVariables\Project\Show as ProjectSharedVariablesShow;
use App\Livewire\SharedVariables\Server\Index as ServerSharedVariablesIndex;
use App\Livewire\SharedVariables\Server\Show as ServerSharedVariablesShow;
use App\Livewire\SharedVariables\Team\Index as TeamSharedVariablesIndex;
use App\Livewire\Source\Github\Change as GitHubChange;
use App\Livewire\Source\Gitlab\Change as GitLabChange;
use App\Livewire\Storage\Index as StorageIndex;
use App\Livewire\Storage\Show as StorageShow;
use App\Livewire\Subscription\Index as SubscriptionIndex;
use App\Livewire\Subscription\Show as SubscriptionShow;
use App\Livewire\Tags\Show as TagsShow;
use App\Livewire\Team\AdminView as TeamAdminView;
use App\Livewire\Team\DangerZone as TeamDangerZone;
use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Team\Member\Index as TeamMemberIndex;
use App\Livewire\Terminal\Index as TerminalIndex;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

Route::post('/forgot-password', [Controller::class, 'forgot_password'])->name('password.forgot')->middleware('throttle:forgot-password');
Route::get('/realtime', [Controller::class, 'realtime_test'])->middleware('auth');
Route::get('/verify', [Controller::class, 'verify'])->middleware('auth')->name('verify.email');
Route::get('/email/verify/{id}/{hash}', [Controller::class, 'email_verify'])->middleware(['auth'])->name('verify.verify');
Route::middleware(['throttle:login'])->group(function () {
    Route::get('/auth/link', [Controller::class, 'link'])->name('auth.link');
});

Route::get('/auth/{provider}/redirect', [OauthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/{provider}/callback', [OauthController::class, 'callback'])->name('auth.callback');

// Local/testing previews for HTTP error pages and the Laravel debug renderer (never in production).
if (app()->environment(['local', 'testing'])) {
    Route::get('/__exception', function () {
        throw new RuntimeException('Testing Laravel exception page');
    })->name('dev.exception-preview');

    Route::get('/__error/{code}', function (string $code) {
        $allowed = ['400', '401', '402', '403', '404', '419', '429', '500', '503'];
        abort_unless(in_array($code, $allowed, true), 404);

        $messages = [
            '400' => 'The request could not be understood by the server due to malformed syntax.',
            '401' => 'You don\'t have permission to access this page.',
            '402' => 'A valid subscription or payment is required to continue.',
            '403' => 'You don\'t have permission to access this page.',
            '404' => 'Sorry, we couldn\'t find the page you\'re looking for.',
            '419' => 'Your session has expired. Please log in again to continue.',
            '429' => 'You\'re making too many requests. Please wait a few seconds before trying again.',
            '500' => 'Example server error: connection to the database timed out.',
            '503' => 'Service unavailable. Be right back. Thanks for your patience.',
        ];

        abort((int) $code, $messages[$code]);
    })->where('code', '[0-9]{3}')->name('dev.error-preview');
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware(['throttle:force-password-reset'])->group(function () {
        Route::get('/force-password-reset', ForcePasswordReset::class)->name('auth.force-password-reset');
    });

    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/admin', AdminIndex::class)->name('admin.index');
    Route::get('/onboarding', BoardingIndex::class)->name('onboarding');

    Route::get('/subscription', SubscriptionShow::class)->name('subscription.show');
    Route::get('/subscription/new', SubscriptionIndex::class)->name('subscription.index');

    Route::get('/settings', SettingsIndex::class)->name('settings.index');
    Route::get('/settings/advanced', SettingsAdvanced::class)->name('settings.advanced');
    Route::get('/settings/updates', SettingsUpdates::class)->name('settings.updates');

    Route::get('/settings/backup', SettingsBackup::class)->name('settings.backup');
    Route::get('/settings/email', SettingsEmail::class)->name('settings.email');
    Route::get('/settings/oauth', SettingsOauth::class)->name('settings.oauth');
    Route::get('/settings/scheduled-jobs', SettingsScheduledJobs::class)->name('settings.scheduled-jobs');

    Route::get('/profile', ProfileIndex::class)->name('profile');
    Route::get('/profile/avatar', ProfileAvatarController::class)->name('profile.avatar');
    Route::get('/profile/appearance', ProfileAppearance::class)->name('profile.appearance');

    Route::prefix('tags')->group(function () {
        Route::get('/{tagName?}', TagsShow::class)->name('tags.show');
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/email', NotificationEmail::class)->name('notifications.email');
        Route::get('/telegram', NotificationTelegram::class)->name('notifications.telegram');
        Route::get('/discord', NotificationDiscord::class)->name('notifications.discord');
        Route::get('/slack', NotificationSlack::class)->name('notifications.slack');
        Route::get('/pushover', NotificationPushover::class)->name('notifications.pushover');
        Route::get('/webhook', NotificationWebhook::class)->name('notifications.webhook');
    });

    Route::prefix('storages')->group(function () {
        Route::get('/', StorageIndex::class)->name('storage.index');
        Route::get('/{storage_uuid}', StorageShow::class)->name('storage.show');
        Route::get('/{storage_uuid}/danger', StorageShow::class)->name('storage.danger');
        Route::get('/{storage_uuid}/resources', StorageShow::class)->name('storage.resources');
    });
    Route::prefix('shared-variables')->group(function () {
        Route::get('/', SharedVariablesIndex::class)->name('shared-variables.index');
        Route::get('/team', TeamSharedVariablesIndex::class)->name('shared-variables.team.index');
        Route::get('/projects', ProjectSharedVariablesIndex::class)->name('shared-variables.project.index');
        Route::get('/project/{project_uuid}', ProjectSharedVariablesShow::class)->name('shared-variables.project.show');
        Route::get('/environments', EnvironmentSharedVariablesIndex::class)->name('shared-variables.environment.index');
        Route::get('/environments/project/{project_uuid}/environment/{environment_uuid}', EnvironmentSharedVariablesShow::class)->name('shared-variables.environment.show');
        Route::get('/servers', ServerSharedVariablesIndex::class)->name('shared-variables.server.index');
        Route::get('/server/{server_uuid}', ServerSharedVariablesShow::class)->name('shared-variables.server.show');
    });

    Route::prefix('team')->group(function () {
        Route::get('/', TeamIndex::class)->name('team.index');
        Route::get('/members', TeamMemberIndex::class)->name('team.member.index');
        Route::get('/admin', TeamAdminView::class)->name('team.admin-view');
        Route::get('/danger', TeamDangerZone::class)->name('team.danger-zone');
    });

    Route::get('/terminal', TerminalIndex::class)->name('terminal')->middleware('can.access.terminal');
    Route::post('/terminal/auth', function () {
        if (auth()->check()) {
            return response()->json(['authenticated' => true], 200);
        }

        return response()->json(['authenticated' => false], 401);
    })->name('terminal.auth')->middleware('can.access.terminal');

    Route::post('/terminal/auth/ips', function () {
        if (auth()->check()) {
            $team = auth()->user()->currentTeam();
            $ipAddresses = $team->servers
                ->where('settings.is_terminal_enabled', true)
                ->pluck('ip')
                ->filter()
                ->values();

            if (isDev()) {
                $ipAddresses = $ipAddresses->merge([
                    'coolify-testing-host',
                    'host.docker.internal',
                    'localhost',
                    '127.0.0.1',
                    base_ip(),
                ])->filter()->unique()->values();
            }

            return response()->json(['ipAddresses' => $ipAddresses->all()], 200);
        }

        return response()->json(['ipAddresses' => []], 401);
    })->name('terminal.auth.ips')->middleware('can.access.terminal');

    Route::prefix('invitations')->group(function () {
        Route::get('/{uuid}', [Controller::class, 'showInvitation'])->name('team.invitation.show');
        Route::post('/{uuid}', [Controller::class, 'acceptInvitation'])->name('team.invitation.accept');
    });

    Route::get('/projects', ProjectIndex::class)->name('project.index');
    Route::get('/project/{project_uuid}/icon', ProjectIconController::class)->name('project.icon');
    Route::prefix('project/{project_uuid}')->group(function () {
        Route::get('/', ProjectShow::class)->name('project.show');
        Route::get('/edit', ProjectEdit::class)->name('project.edit')->middleware('can.update.resource');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}')->group(function () {
        Route::get('/', ResourceIndex::class)->name('project.resource.index');
        Route::get('/clone', ProjectCloneMe::class)->name('project.clone-me')->middleware('can.create.resources');
        Route::get('/new', ResourceCreate::class)->name('project.resource.create')->middleware('can.create.resources');
        Route::get('/edit', EnvironmentEdit::class)->name('project.environment.edit')->middleware('can.update.resource');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}')->group(function () {
        Route::get('/', ApplicationConfiguration::class)->name('project.application.configuration');
        Route::get('/domains', ApplicationConfiguration::class)->name('project.application.domains');
        Route::get('/swarm', ApplicationConfiguration::class)->name('project.application.swarm');
        Route::get('/advanced', ApplicationConfiguration::class)->name('project.application.advanced');
        Route::get('/environment-variables', ApplicationConfiguration::class)->name('project.application.environment-variables');
        Route::get('/persistent-storage', ApplicationConfiguration::class)->name('project.application.persistent-storage');
        Route::get('/backups', ApplicationBackupIndex::class)->name('project.application.backup.index');
        Route::get('/backups/{backup_uuid}', ApplicationBackupShow::class)->name('project.application.backup.show');
        Route::get('/backups/{backup_uuid}/s3', ApplicationBackupShow::class)->name('project.application.backup.s3');
        Route::get('/backups/{backup_uuid}/retention', ApplicationBackupShow::class)->name('project.application.backup.retention');
        Route::get('/backups/{backup_uuid}/executions', ApplicationBackupShow::class)->name('project.application.backup.executions');
        Route::get('/backups/{backup_uuid}/danger', ApplicationBackupShow::class)->name('project.application.backup.danger');
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
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.application.command')->middleware('can.access.terminal');
        Route::get('/tasks/{task_uuid}', ApplicationConfiguration::class)->name('project.application.scheduled-tasks');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}')->group(function () {
        Route::get('/', DatabaseConfiguration::class)->name('project.database.configuration');
        Route::get('/environment-variables', DatabaseConfiguration::class)->name('project.database.environment-variables');
        Route::get('/servers', DatabaseConfiguration::class)->name('project.database.servers');
        Route::get('/import-backup', DatabaseConfiguration::class)->name('project.database.import-backup')->middleware('can.update.resource');
        Route::get('/persistent-storage', DatabaseConfiguration::class)->name('project.database.persistent-storage');
        Route::get('/healthcheck', DatabaseConfiguration::class)->name('project.database.healthcheck');
        Route::get('/webhooks', DatabaseConfiguration::class)->name('project.database.webhooks');
        Route::get('/resource-limits', DatabaseConfiguration::class)->name('project.database.resource-limits');
        Route::get('/resource-operations', DatabaseConfiguration::class)->name('project.database.resource-operations');
        Route::get('/metrics', DatabaseConfiguration::class)->name('project.database.metrics');
        Route::get('/tags', DatabaseConfiguration::class)->name('project.database.tags');
        Route::get('/danger', DatabaseConfiguration::class)->name('project.database.danger');

        Route::get('/logs', Logs::class)->name('project.database.logs');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.database.command')->middleware('can.access.terminal');
        Route::get('/backups', DatabaseBackupIndex::class)->name('project.database.backup.index');
        Route::get('/backups/{backup_uuid}', DatabaseBackupExecution::class)->name('project.database.backup.execution');
        Route::get('/backups/{backup_uuid}/s3', DatabaseBackupExecution::class)->name('project.database.backup.s3');
        Route::get('/backups/{backup_uuid}/retention', DatabaseBackupExecution::class)->name('project.database.backup.retention');
        Route::get('/backups/{backup_uuid}/executions', DatabaseBackupExecution::class)->name('project.database.backup.executions');
        Route::get('/backups/{backup_uuid}/danger', DatabaseBackupExecution::class)->name('project.database.backup.danger');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}')->group(function () {
        Route::get('/', ServiceConfiguration::class)->name('project.service.configuration');
        Route::get('/domains', ServiceConfiguration::class)->name('project.service.domains');
        Route::get('/logs', Logs::class)->name('project.service.logs');
        Route::get('/environment-variables', ServiceConfiguration::class)->name('project.service.environment-variables');
        Route::get('/storages', ServiceConfiguration::class)->name('project.service.storages');
        Route::get('/storage-backups', Index::class)->name('project.service.volume-backups.index');
        Route::get('/storage-backups/{backup_uuid}', Show::class)->name('project.service.volume-backups.show');
        Route::get('/storage-backups/{backup_uuid}/s3', Show::class)->name('project.service.volume-backups.s3');
        Route::get('/storage-backups/{backup_uuid}/retention', Show::class)->name('project.service.volume-backups.retention');
        Route::get('/storage-backups/{backup_uuid}/executions', Show::class)->name('project.service.volume-backups.executions');
        Route::get('/storage-backups/{backup_uuid}/danger', Show::class)->name('project.service.volume-backups.danger');
        Route::get('/scheduled-tasks', ServiceConfiguration::class)->name('project.service.scheduled-tasks.show');
        Route::get('/webhooks', ServiceConfiguration::class)->name('project.service.webhooks');
        Route::get('/resource-operations', ServiceConfiguration::class)->name('project.service.resource-operations');
        Route::get('/tags', ServiceConfiguration::class)->name('project.service.tags');
        Route::get('/danger', ServiceConfiguration::class)->name('project.service.danger');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.service.command')->middleware('can.access.terminal');
        Route::get('/{stack_service_uuid}/backups', ServiceDatabaseBackups::class)->name('project.service.database.backups');
        Route::get('/{stack_service_uuid}/backups/{backup_uuid}', ServiceDatabaseBackups::class)->name('project.service.database.backup.show');
        Route::get('/{stack_service_uuid}/backups/{backup_uuid}/s3', ServiceDatabaseBackups::class)->name('project.service.database.backup.s3');
        Route::get('/{stack_service_uuid}/backups/{backup_uuid}/retention', ServiceDatabaseBackups::class)->name('project.service.database.backup.retention');
        Route::get('/{stack_service_uuid}/backups/{backup_uuid}/executions', ServiceDatabaseBackups::class)->name('project.service.database.backup.executions');
        Route::get('/{stack_service_uuid}/backups/{backup_uuid}/danger', ServiceDatabaseBackups::class)->name('project.service.database.backup.danger');
        Route::get('/{stack_service_uuid}/import', ServiceIndex::class)->name('project.service.database.import')->middleware('can.update.resource');
        Route::get('/{stack_service_uuid}/advanced', ServiceIndex::class)->name('project.service.index.advanced');
        Route::get('/{stack_service_uuid}', ServiceIndex::class)->name('project.service.index');
        Route::get('/tasks/{task_uuid}', ServiceConfiguration::class)->name('project.service.scheduled-tasks');
    });

    Route::get('/servers', ServerIndex::class)->name('server.index');
    Route::get('/servers/import', ServerTransferImport::class)->name('server.transfer.import')->middleware('can:create,'.Server::class);
    Route::get('/servers/new', ServerCreatePage::class)->name('server.create')->middleware('can:create,'.Server::class);
    Route::get('/servers/new/{type}/{token_uuid}', ServerCreatePage::class)->name('server.create.token')->middleware('can:create,'.Server::class)->whereIn('type', ['hetzner', 'vultr', 'digital-ocean']);
    Route::get('/servers/new/{type}', ServerCreatePage::class)->name('server.create.type')->middleware('can:create,'.Server::class)->whereIn('type', ['hetzner', 'vultr', 'digital-ocean', 'manual']);

    Route::prefix('server/{server_uuid}')->group(function () {
        Route::get('/', ServerShow::class)->name('server.show');
        Route::get('/advanced', ServerAdvanced::class)->name('server.advanced');
        Route::get('/swarm', ServerSwarm::class)->name('server.swarm');
        Route::get('/sentinel', SentinelShow::class)->name('server.sentinel');
        Route::get('/sentinel/logs', SentinelLogs::class)->name('server.sentinel.logs');
        Route::get('/private-key', PrivateKeyShow::class)->name('server.private-key');
        Route::get('/cloud-provider-token', CloudProviderTokenShow::class)->name('server.cloud-provider-token');
        Route::get('/ca-certificate', CaCertificateShow::class)->name('server.ca-certificate');
        Route::get('/resources', ResourcesShow::class)->name('server.resources');
        Route::get('/cloudflare-tunnel', CloudflareTunnel::class)->name('server.cloudflare-tunnel');
        Route::get('/destinations', ServerDestinations::class)->name('server.destinations');
        Route::get('/log-drains', LogDrains::class)->name('server.log-drains');
        Route::get('/metrics', ServerCharts::class)->name('server.metrics');
        Route::get('/danger', DeleteServer::class)->name('server.delete');
        Route::get('/transfer', ServerTransfer::class)->name('server.transfer');
        Route::get('/proxy', ProxyShow::class)->name('server.proxy');
        Route::get('/proxy/dynamic', ProxyDynamicConfigurations::class)->name('server.proxy.dynamic-confs');
        Route::get('/proxy/logs', ProxyLogs::class)->name('server.proxy.logs');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('server.command')->middleware('can.access.terminal');
        Route::get('/docker-cleanup', DockerCleanup::class)->name('server.docker-cleanup');
        Route::get('/security', fn () => redirect(route('dashboard')))->name('server.security')->middleware('can.update.resource');
        Route::get('/security/patches', Patches::class)->name('server.security.patches')->middleware('can.update.resource');
        Route::get('/security/terminal-access', TerminalAccess::class)->name('server.security.terminal-access')->middleware('can.update.resource');
    });
    Route::get('/destinations', DestinationIndex::class)->name('destination.index');
    Route::get('/destination/{destination_uuid}', DestinationShow::class)->name('destination.show');
    Route::get('/destination/{destination_uuid}/danger', DestinationShow::class)->name('destination.danger');
    Route::get('/destination/{destination_uuid}/resources', DestinationResources::class)->name('destination.resources');

    // Route::get('/security', fn () => view('security.index'))->name('security.index');
    Route::get('/security/private-key', SecurityPrivateKeyIndex::class)->name('security.private-key.index');
    // Route::get('/security/private-key/new', SecurityPrivateKeyCreate::class)->name('security.private-key.create');
    Route::get('/security/private-key/{private_key_uuid}', SecurityPrivateKeyShow::class)->name('security.private-key.show');

    Route::get('/security/cloud-tokens', CloudTokens::class)->name('security.cloud-tokens');
    Route::get('/security/cloud-tokens/{cloud_token_uuid}', SecurityCloudProviderTokenShow::class)->name('security.cloud-tokens.show');
    Route::get('/security/cloud-init-scripts', CloudInitScripts::class)->name('security.cloud-init-scripts');
    Route::get('/security/cloud-init-scripts/{cloud_init_script_uuid}', SecurityCloudInitScriptShow::class)->name('security.cloud-init-scripts.show');
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
    Route::get('/source/github/{github_app_uuid}/danger', GitHubChange::class)->name('source.github.danger');
    Route::get('/source/github/{github_app_uuid}/permissions', GitHubChange::class)->name('source.github.permissions');
    Route::get('/source/github/{github_app_uuid}/resources', GitHubChange::class)->name('source.github.resources');
    Route::get('/source/gitlab/{gitlab_app_uuid}', GitLabChange::class)->name('source.gitlab.show');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/upload/backup/{databaseUuid}', [UploadController::class, 'upload'])->name('upload.backup');
    Route::get('/download/backup/{executionId}', function () {
        try {
            $user = auth()->user();
            $team = $user->currentTeam();
            if (is_null($team)) {
                return response()->json(['message' => 'Team not found.'], 404);
            }
            if ($user->isAdminFromSession() === false) {
                return response()->json(['message' => 'Only team admins/owners can download backups.'], 403);
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
            if ($execution->scheduledDatabaseBackup->database->getMorphClass() === ServiceDatabase::class) {
                $server = $execution->scheduledDatabaseBackup->database->service->destination->server;
            } else {
                $server = $execution->scheduledDatabaseBackup->database->destination->server;
            }

            return streamBackupFromServer($server, $filename, 'application/octet-stream');
        } catch (FileNotFoundException) {
            if (isset($execution) && $execution->scheduledDatabaseBackup->disable_local_backup === true && $execution->scheduledDatabaseBackup->save_s3 === true) {
                return response()->json(['message' => 'Backup not available locally, but available on S3.'], 404);
            }

            return response()->json(['message' => 'Backup not found locally on the server.'], 404);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Failed to download backup.'], 500);
        }
    })->name('download.backup');

    Route::get('/download/volume-backup/{executionId}', function () {
        try {
            $user = auth()->user();
            $team = $user->currentTeam();
            if (is_null($team)) {
                return response()->json(['message' => 'Team not found.'], 404);
            }
            if ($user->isAdminFromSession() === false) {
                return response()->json(['message' => 'Only team admins/owners can download backups.'], 403);
            }

            $execution = ScheduledVolumeBackupExecution::query()
                ->with('scheduledVolumeBackup.backupable.resource')
                ->findOrFail(request()->route('executionId'));
            if ($team->id !== 0 && $team->id !== $execution->scheduledVolumeBackup->team_id) {
                return response()->json(['message' => 'Permission denied.'], 403);
            }
            if ($execution->local_storage_deleted || blank($execution->filename)) {
                return response()->json(['message' => 'Backup not found locally on the server.'], 404);
            }

            $server = $execution->scheduledVolumeBackup->server();
            if (! $server) {
                return response()->json(['message' => 'Server not found.'], 404);
            }

            return streamBackupFromServer($server, $execution->filename, 'application/gzip');
        } catch (FileNotFoundException) {
            return response()->json(['message' => 'Backup not found locally on the server.'], 404);
        } catch (Throwable) {
            return response()->json(['message' => 'Failed to download backup.'], 500);
        }
    })->name('download.volume-backup');

});

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }

    return redirect()->route('login');
})->where('any', '.*');
