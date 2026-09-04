<?php

use App\Http\Controllers\Api\ApplicationsController;
use App\Http\Controllers\Api\CloudInitScriptsController;
use App\Http\Controllers\Api\CloudProviderTokensController;
use App\Http\Controllers\Api\DatabasesController;
use App\Http\Controllers\Api\DeployController;
use App\Http\Controllers\Api\DestinationsController;
use App\Http\Controllers\Api\DigitalOceanController;
use App\Http\Controllers\Api\GithubController;
use App\Http\Controllers\Api\GitlabController;
use App\Http\Controllers\Api\HetznerController;
use App\Http\Controllers\Api\InstanceEmailSettingsController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\OtherController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ResourcesController;
use App\Http\Controllers\Api\S3StoragesController;
use App\Http\Controllers\Api\ScheduledTasksController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SentinelController;
use App\Http\Controllers\Api\ServerCloudflareTunnelController;
use App\Http\Controllers\Api\ServerDockerCleanupController;
use App\Http\Controllers\Api\ServerLogDrainsController;
use App\Http\Controllers\Api\ServerProxyController;
use App\Http\Controllers\Api\ServersController;
use App\Http\Controllers\Api\ServerSentinelController;
use App\Http\Controllers\Api\ServerTransferController;
use App\Http\Controllers\Api\ServiceApplicationsController;
use App\Http\Controllers\Api\ServiceDatabasesController;
use App\Http\Controllers\Api\ServicesController;
use App\Http\Controllers\Api\SharedEnvironmentVariablesController;
use App\Http\Controllers\Api\TagsController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\VolumeBackupsController;
use App\Http\Controllers\Api\VultrController;
use App\Http\Middleware\ApiAllowed;
use Illuminate\Support\Facades\Route;

Route::get('/health', [OtherController::class, 'healthcheck']);
Route::group([
    'prefix' => 'v1',
], function () {
    Route::get('/health', [OtherController::class, 'healthcheck']);
});

Route::post('/feedback', [OtherController::class, 'feedback'])
    ->middleware('throttle:feedback');

Route::group([
    'middleware' => ['auth:sanctum', 'api.token.team', 'api.ability:write'],
    'prefix' => 'v1',
], function () {
    Route::get('/enable', [OtherController::class, 'post_required']);
    Route::get('/disable', [OtherController::class, 'post_required']);
    Route::post('/enable', [OtherController::class, 'enable_api']);
    Route::post('/disable', [OtherController::class, 'disable_api']);
    Route::post('/mcp/enable', [OtherController::class, 'enable_mcp']);
    Route::post('/mcp/disable', [OtherController::class, 'disable_mcp']);
});
Route::group([
    'middleware' => ['auth:sanctum', 'api.token.team', ApiAllowed::class, 'api.sensitive'],
    'prefix' => 'v1',
], function () {

    Route::get('/version', [OtherController::class, 'version'])->middleware(['api.ability:read']);

    Route::get('/teams', [TeamController::class, 'teams'])->middleware(['api.ability:read']);
    // Token's team
    Route::get('/team', [TeamController::class, 'current_team'])->middleware(['api.ability:read']);
    Route::get('/team/members', [TeamController::class, 'current_team_members'])->middleware(['api.ability:read']);
    // Deprecated aliases — same handlers as /team and /team/members (remove in a later release)
    Route::get('/teams/current', [TeamController::class, 'current_team'])->middleware(['api.ability:read']);
    Route::get('/teams/current/members', [TeamController::class, 'current_team_members'])->middleware(['api.ability:read']);
    Route::get('/notifications/email', [NotificationsController::class, 'email'])->middleware(['api.ability:read']);
    Route::patch('/notifications/email', [NotificationsController::class, 'update_email'])->middleware(['api.ability:write']);
    Route::get('/notifications/discord', [NotificationsController::class, 'discord'])->middleware(['api.ability:read']);
    Route::patch('/notifications/discord', [NotificationsController::class, 'update_discord'])->middleware(['api.ability:write']);
    Route::get('/notifications/slack', [NotificationsController::class, 'slack'])->middleware(['api.ability:read']);
    Route::patch('/notifications/slack', [NotificationsController::class, 'update_slack'])->middleware(['api.ability:write']);
    Route::get('/notifications/telegram', [NotificationsController::class, 'telegram'])->middleware(['api.ability:read']);
    Route::patch('/notifications/telegram', [NotificationsController::class, 'update_telegram'])->middleware(['api.ability:write']);
    Route::get('/notifications/pushover', [NotificationsController::class, 'pushover'])->middleware(['api.ability:read']);
    Route::patch('/notifications/pushover', [NotificationsController::class, 'update_pushover'])->middleware(['api.ability:write']);
    Route::get('/notifications/webhook', [NotificationsController::class, 'webhook'])->middleware(['api.ability:read']);
    Route::patch('/notifications/webhook', [NotificationsController::class, 'update_webhook'])->middleware(['api.ability:write']);
    Route::get('/settings/email', [InstanceEmailSettingsController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/settings/email', [InstanceEmailSettingsController::class, 'update'])->middleware(['api.ability:write:sensitive']);
    Route::get('/team/envs', [SharedEnvironmentVariablesController::class, 'team_envs'])->middleware(['api.ability:read']);
    Route::post('/team/envs', [SharedEnvironmentVariablesController::class, 'team_create_env'])->middleware(['api.ability:write']);
    Route::patch('/team/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'team_update_env'])->middleware(['api.ability:write']);
    Route::delete('/team/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'team_delete_env'])->middleware(['api.ability:write']);
    Route::get('/teams/{id}', [TeamController::class, 'team_by_id'])->middleware(['api.ability:read']);
    Route::get('/teams/{id}/members', [TeamController::class, 'members_by_id'])->middleware(['api.ability:read']);

    Route::get('/projects', [ProjectController::class, 'projects'])->middleware(['api.ability:read']);
    Route::get('/projects/{uuid}', [ProjectController::class, 'project_by_uuid'])->middleware(['api.ability:read']);
    Route::get('/projects/{uuid}/environments', [ProjectController::class, 'get_environments'])->middleware(['api.ability:read']);
    // Shared project/environment envs must be registered before the catch-all environment route.
    Route::get('/projects/{uuid}/envs', [SharedEnvironmentVariablesController::class, 'project_envs'])->middleware(['api.ability:read']);
    Route::post('/projects/{uuid}/envs', [SharedEnvironmentVariablesController::class, 'project_create_env'])->middleware(['api.ability:write']);
    Route::patch('/projects/{uuid}/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'project_update_env'])->middleware(['api.ability:write']);
    Route::delete('/projects/{uuid}/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'project_delete_env'])->middleware(['api.ability:write']);
    Route::get('/projects/{uuid}/environments/{environment_name_or_uuid}/envs', [SharedEnvironmentVariablesController::class, 'environment_envs'])->middleware(['api.ability:read']);
    Route::post('/projects/{uuid}/environments/{environment_name_or_uuid}/envs', [SharedEnvironmentVariablesController::class, 'environment_create_env'])->middleware(['api.ability:write']);
    Route::patch('/projects/{uuid}/environments/{environment_name_or_uuid}/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'environment_update_env'])->middleware(['api.ability:write']);
    Route::delete('/projects/{uuid}/environments/{environment_name_or_uuid}/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'environment_delete_env'])->middleware(['api.ability:write']);
    Route::get('/projects/{uuid}/{environment_name_or_uuid}', [ProjectController::class, 'environment_details'])->middleware(['api.ability:read']);
    Route::post('/projects/{uuid}/environments', [ProjectController::class, 'create_environment'])->middleware(['api.ability:write']);
    Route::patch('/projects/{uuid}/environments/{environment_name_or_uuid}', [ProjectController::class, 'update_environment'])->middleware(['api.ability:write']);
    Route::delete('/projects/{uuid}/environments/{environment_name_or_uuid}', [ProjectController::class, 'delete_environment'])->middleware(['api.ability:write']);

    Route::post('/projects', [ProjectController::class, 'create_project'])->middleware(['api.ability:write']);
    Route::patch('/projects/{uuid}', [ProjectController::class, 'update_project'])->middleware(['api.ability:write']);
    Route::delete('/projects/{uuid}', [ProjectController::class, 'delete_project'])->middleware(['api.ability:write']);

    Route::get('/security/keys', [SecurityController::class, 'keys'])->middleware(['api.ability:read']);
    Route::post('/security/keys', [SecurityController::class, 'create_key'])->middleware(['api.ability:write']);

    Route::get('/security/keys/{uuid}', [SecurityController::class, 'key_by_uuid'])->middleware(['api.ability:read']);
    Route::patch('/security/keys/{uuid}', [SecurityController::class, 'update_key'])->middleware(['api.ability:write']);
    Route::delete('/security/keys/{uuid}', [SecurityController::class, 'delete_key'])->middleware(['api.ability:write']);

    Route::get('/cloud-tokens', [CloudProviderTokensController::class, 'index'])->middleware(['api.ability:read']);
    Route::post('/cloud-tokens', [CloudProviderTokensController::class, 'store'])->middleware(['api.ability:write']);
    Route::get('/cloud-tokens/{uuid}', [CloudProviderTokensController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/cloud-tokens/{uuid}', [CloudProviderTokensController::class, 'update'])->middleware(['api.ability:write']);
    Route::delete('/cloud-tokens/{uuid}', [CloudProviderTokensController::class, 'destroy'])->middleware(['api.ability:write']);
    Route::post('/cloud-tokens/{uuid}/validate', [CloudProviderTokensController::class, 'validateToken'])->middleware(['api.ability:write']);

    Route::get('/cloud-init-scripts', [CloudInitScriptsController::class, 'index'])->middleware(['api.ability:read']);
    Route::post('/cloud-init-scripts', [CloudInitScriptsController::class, 'store'])->middleware(['api.ability:write']);
    Route::get('/cloud-init-scripts/{uuid}', [CloudInitScriptsController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/cloud-init-scripts/{uuid}', [CloudInitScriptsController::class, 'update'])->middleware(['api.ability:write']);
    Route::delete('/cloud-init-scripts/{uuid}', [CloudInitScriptsController::class, 'destroy'])->middleware(['api.ability:write']);

    Route::get('/s3-storages', [S3StoragesController::class, 'index'])->middleware(['api.ability:read']);
    Route::post('/s3-storages', [S3StoragesController::class, 'store'])->middleware(['api.ability:write']);
    Route::get('/s3-storages/{uuid}', [S3StoragesController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/s3-storages/{uuid}', [S3StoragesController::class, 'update'])->middleware(['api.ability:write']);
    Route::delete('/s3-storages/{uuid}', [S3StoragesController::class, 'destroy'])->middleware(['api.ability:write']);
    Route::post('/s3-storages/{uuid}/validate', [S3StoragesController::class, 'validateStorage'])->middleware(['api.ability:write']);

    Route::get('/deploy', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::post('/deploy', [DeployController::class, 'deploy'])->middleware(['api.ability:deploy']);
    Route::get('/deployments', [DeployController::class, 'deployments'])->middleware(['api.ability:read']);
    Route::get('/deployments/{uuid}', [DeployController::class, 'deployment_by_uuid'])->middleware(['api.ability:read']);
    Route::post('/deployments/{uuid}/cancel', [DeployController::class, 'cancel_deployment'])->middleware(['api.ability:deploy']);
    Route::get('/deployments/applications/{uuid}', [DeployController::class, 'get_application_deployments'])->middleware(['api.ability:read']);

    Route::get('/servers', [ServersController::class, 'servers'])->middleware(['api.ability:read']);
    Route::get('/servers/{uuid}', [ServersController::class, 'server_by_uuid'])->middleware(['api.ability:read']);
    Route::get('/servers/{uuid}/domains', [ServersController::class, 'domains_by_server'])->middleware(['api.ability:read']);
    Route::get('/servers/{uuid}/resources', [ServersController::class, 'resources_by_server'])->middleware(['api.ability:read']);
    Route::get('/servers/{uuid}/envs', [SharedEnvironmentVariablesController::class, 'server_envs'])->middleware(['api.ability:read']);
    Route::post('/servers/{uuid}/envs', [SharedEnvironmentVariablesController::class, 'server_create_env'])->middleware(['api.ability:write']);
    Route::patch('/servers/{uuid}/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'server_update_env'])->middleware(['api.ability:write']);
    Route::delete('/servers/{uuid}/envs/{env_id}', [SharedEnvironmentVariablesController::class, 'server_delete_env'])->middleware(['api.ability:write']);

    // Server subsystem APIs (Docker cleanup, log drains, Sentinel, Cloudflare Tunnel).
    Route::get('/servers/{uuid}/docker-cleanup', [ServerDockerCleanupController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/servers/{uuid}/docker-cleanup', [ServerDockerCleanupController::class, 'update'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/docker-cleanup/run', [ServerDockerCleanupController::class, 'run'])->middleware(['api.ability:write']);
    Route::get('/servers/{uuid}/docker-cleanup/executions', [ServerDockerCleanupController::class, 'executions'])->middleware(['api.ability:read']);

    Route::get('/servers/{uuid}/log-drains', [ServerLogDrainsController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/servers/{uuid}/log-drains', [ServerLogDrainsController::class, 'update'])->middleware(['api.ability:write']);

    Route::get('/servers/{uuid}/sentinel', [ServerSentinelController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/servers/{uuid}/sentinel', [ServerSentinelController::class, 'update'])->middleware(['api.ability:write']);

    Route::get('/servers/{uuid}/cloudflare-tunnel', [ServerCloudflareTunnelController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/servers/{uuid}/cloudflare-tunnel', [ServerCloudflareTunnelController::class, 'update'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/cloudflare-tunnel/enable', [ServerCloudflareTunnelController::class, 'enable'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/cloudflare-tunnel/disable', [ServerCloudflareTunnelController::class, 'disable'])->middleware(['api.ability:write']);

    // Destinations — REST surface for the Coolify "Destinations" UI section (added).
    Route::get('/destinations', [DestinationsController::class, 'index'])->middleware(['api.ability:read']);
    Route::get('/destinations/{uuid}', [DestinationsController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/destinations/{uuid}', [DestinationsController::class, 'update'])->middleware(['api.ability:write']);
    Route::delete('/destinations/{uuid}', [DestinationsController::class, 'delete'])->middleware(['api.ability:write']);
    Route::get('/servers/{server_uuid}/destinations', [DestinationsController::class, 'index_by_server'])->middleware(['api.ability:read']);
    Route::post('/servers/{server_uuid}/destinations', [DestinationsController::class, 'create'])->middleware(['api.ability:write']);

    Route::get('/servers/{uuid}/validate', [OtherController::class, 'post_required'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/validate', [ServersController::class, 'validate_server'])->middleware(['api.ability:write']);

    Route::get('/servers/{uuid}/proxy', [ServerProxyController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/servers/{uuid}/proxy', [ServerProxyController::class, 'update'])->middleware(['api.ability:write']);
    Route::put('/servers/{uuid}/proxy/configuration', [ServerProxyController::class, 'saveConfiguration'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/proxy/restart', [ServerProxyController::class, 'restart'])->middleware(['api.ability:write']);

    Route::post('/servers', [ServersController::class, 'create_server'])->middleware(['api.ability:write']);
    Route::post('/servers/import', [ServerTransferController::class, 'import'])->middleware(['api.ability:write']);
    Route::get('/servers/{uuid}/export', [ServerTransferController::class, 'export'])->middleware(['api.ability:read']);
    Route::post('/servers/{uuid}/export/mailbox', [ServerTransferController::class, 'writeMailbox'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/claim', [ServerTransferController::class, 'claim'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/transfer/complete', [ServerTransferController::class, 'complete'])->middleware(['api.ability:write']);
    Route::post('/servers/{uuid}/migrate', [ServerTransferController::class, 'migrate'])->middleware(['api.ability:write']);
    Route::patch('/servers/{uuid}', [ServersController::class, 'update_server'])->middleware(['api.ability:write']);
    Route::delete('/servers/{uuid}', [ServersController::class, 'delete_server'])->middleware(['api.ability:write']);

    Route::get('/hetzner/locations', [HetznerController::class, 'locations'])->middleware(['api.ability:read']);
    Route::get('/hetzner/server-types', [HetznerController::class, 'serverTypes'])->middleware(['api.ability:read']);
    Route::get('/hetzner/images', [HetznerController::class, 'images'])->middleware(['api.ability:read']);
    Route::get('/hetzner/ssh-keys', [HetznerController::class, 'sshKeys'])->middleware(['api.ability:read']);
    Route::get('/hetzner/firewalls', [HetznerController::class, 'firewalls'])->middleware(['api.ability:read']);
    Route::get('/hetzner/networks', [HetznerController::class, 'networks'])->middleware(['api.ability:read']);
    Route::post('/servers/hetzner', [HetznerController::class, 'createServer'])->middleware(['api.ability:write']);

    Route::get('/vultr/regions', [VultrController::class, 'regions'])->middleware(['api.ability:read']);
    Route::get('/vultr/plans', [VultrController::class, 'plans'])->middleware(['api.ability:read']);
    Route::get('/vultr/os', [VultrController::class, 'operatingSystems'])->middleware(['api.ability:read']);
    Route::get('/vultr/ssh-keys', [VultrController::class, 'sshKeys'])->middleware(['api.ability:read']);
    Route::post('/servers/vultr', [VultrController::class, 'createServer'])->middleware(['api.ability:write']);

    Route::get('/digitalocean/regions', [DigitalOceanController::class, 'regions'])->middleware(['api.ability:read']);
    Route::get('/digitalocean/sizes', [DigitalOceanController::class, 'sizes'])->middleware(['api.ability:read']);
    Route::get('/digitalocean/images', [DigitalOceanController::class, 'images'])->middleware(['api.ability:read']);
    Route::get('/digitalocean/ssh-keys', [DigitalOceanController::class, 'sshKeys'])->middleware(['api.ability:read']);
    Route::post('/servers/digitalocean', [DigitalOceanController::class, 'createServer'])->middleware(['api.ability:write']);

    Route::get('/resources', [ResourcesController::class, 'resources'])->middleware(['api.ability:read']);

    Route::get('/tags', [TagsController::class, 'tags'])->middleware(['api.ability:read']);
    Route::post('/tags', [TagsController::class, 'create'])->middleware(['api.ability:write']);
    Route::patch('/tags/{uuid}', [TagsController::class, 'update'])->middleware(['api.ability:write']);
    Route::delete('/tags/{uuid}', [TagsController::class, 'delete'])->middleware(['api.ability:write']);

    Route::get('/applications', [ApplicationsController::class, 'applications'])->middleware(['api.ability:read']);
    Route::post('/applications/public', [ApplicationsController::class, 'create_public_application'])->middleware(['api.ability:write']);
    Route::post('/applications/private-github-app', [ApplicationsController::class, 'create_private_gh_app_application'])->middleware(['api.ability:write']);
    Route::post('/applications/private-deploy-key', [ApplicationsController::class, 'create_private_deploy_key_application'])->middleware(['api.ability:write']);
    Route::post('/applications/dockerfile', [ApplicationsController::class, 'create_dockerfile_application'])->middleware(['api.ability:write']);
    Route::post('/applications/dockerimage', [ApplicationsController::class, 'create_dockerimage_application'])->middleware(['api.ability:write']);

    Route::get('/applications/{uuid}', [ApplicationsController::class, 'application_by_uuid'])->middleware(['api.ability:read']);
    Route::patch('/applications/{uuid}', [ApplicationsController::class, 'update_by_uuid'])->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}', [ApplicationsController::class, 'delete_by_uuid'])->middleware(['api.ability:write']);

    Route::get('/applications/{uuid}/envs', [ApplicationsController::class, 'envs'])->middleware(['api.ability:read']);
    Route::post('/applications/{uuid}/envs', [ApplicationsController::class, 'create_env'])->middleware(['api.ability:write']);
    Route::patch('/applications/{uuid}/envs/bulk', [ApplicationsController::class, 'create_bulk_envs'])->middleware(['api.ability:write']);
    Route::patch('/applications/{uuid}/envs', [ApplicationsController::class, 'update_env_by_uuid'])->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}/envs/{env_uuid}', [ApplicationsController::class, 'delete_env_by_uuid'])->middleware(['api.ability:write']);
    Route::get('/applications/{uuid}/logs', [ApplicationsController::class, 'logs_by_uuid'])->middleware(['api.ability:read']);
    Route::get('/applications/{uuid}/storages', [ApplicationsController::class, 'storages'])->middleware(['api.ability:read']);
    Route::post('/applications/{uuid}/storages', [ApplicationsController::class, 'create_storage'])->middleware(['api.ability:write']);
    Route::patch('/applications/{uuid}/storages', [ApplicationsController::class, 'update_storage'])->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}/storages/{storage_uuid}', [ApplicationsController::class, 'delete_storage'])->middleware(['api.ability:write']);
    Route::put('/applications/{uuid}/storages/{storage_uuid}/backups', [VolumeBackupsController::class, 'upsert'])
        ->defaults('resource_type', 'application')
        ->middleware(['api.ability:write']);
    Route::post('/applications/{uuid}/storages/{storage_uuid}/backups/run', [VolumeBackupsController::class, 'run'])
        ->defaults('resource_type', 'application')
        ->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}/storages/{storage_uuid}/backups', [VolumeBackupsController::class, 'destroy'])
        ->defaults('resource_type', 'application')
        ->middleware(['api.ability:write']);

    Route::get('/applications/{uuid}/tags', [ApplicationsController::class, 'tags'])->middleware(['api.ability:read']);
    Route::post('/applications/{uuid}/tags', [ApplicationsController::class, 'create_tag'])->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}/tags/{tag_uuid}', [ApplicationsController::class, 'delete_tag'])->middleware(['api.ability:write']);

    Route::post('/applications/{uuid}/move', [ApplicationsController::class, 'move_by_uuid'])->middleware(['api.ability:write']);
    Route::post('/applications/{uuid}/migrate', [ApplicationsController::class, 'migrate_by_uuid'])->middleware(['api.ability:write']);
    Route::post('/applications/{uuid}/clone', [ApplicationsController::class, 'clone_by_uuid'])->middleware(['api.ability:write']);
    Route::get('/applications/{uuid}/rollback-images', [ApplicationsController::class, 'rollback_images'])->middleware(['api.ability:read']);
    Route::post('/applications/{uuid}/rollback', [ApplicationsController::class, 'rollback_by_uuid'])->middleware(['api.ability:deploy']);
    Route::get('/applications/{uuid}/destinations', [ApplicationsController::class, 'destinations'])->middleware(['api.ability:read']);
    Route::post('/applications/{uuid}/destinations', [ApplicationsController::class, 'add_destination'])->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}/destinations/{destination_uuid}', [ApplicationsController::class, 'remove_destination'])->middleware(['api.ability:write']);
    Route::get('/applications/{uuid}/start', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/applications/{uuid}/restart', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/applications/{uuid}/stop', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::post('/applications/{uuid}/start', [ApplicationsController::class, 'action_deploy'])->middleware(['api.ability:deploy']);
    Route::post('/applications/{uuid}/restart', [ApplicationsController::class, 'action_restart'])->middleware(['api.ability:deploy']);
    Route::post('/applications/{uuid}/stop', [ApplicationsController::class, 'action_stop'])->middleware(['api.ability:deploy']);

    Route::patch('/applications/{uuid}/previews/{pull_request_id}', [ApplicationsController::class, 'update_preview_by_pull_request_id'])->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}/previews/{pull_request_id}', [ApplicationsController::class, 'delete_preview_by_pull_request_id'])->middleware(['api.ability:write']);

    Route::get('/github-apps', [GithubController::class, 'list_github_apps'])->middleware(['api.ability:read']);
    Route::post('/github-apps', [GithubController::class, 'create_github_app'])->middleware(['api.ability:write']);
    Route::patch('/github-apps/{github_app_id}', [GithubController::class, 'update_github_app'])->middleware(['api.ability:write']);
    Route::delete('/github-apps/{github_app_id}', [GithubController::class, 'delete_github_app'])->middleware(['api.ability:write']);
    Route::get('/github-apps/{github_app_id}/repositories', [GithubController::class, 'load_repositories'])->middleware(['api.ability:read']);
    Route::get('/github-apps/{github_app_id}/repositories/{owner}/{repo}/branches', [GithubController::class, 'load_branches'])->middleware(['api.ability:read']);

    Route::get('/gitlab-apps', [GitlabController::class, 'list_gitlab_apps'])->middleware(['api.ability:read']);
    Route::post('/gitlab-apps', [GitlabController::class, 'create_gitlab_app'])->middleware(['api.ability:write']);
    Route::patch('/gitlab-apps/{gitlab_app_id}', [GitlabController::class, 'update_gitlab_app'])->middleware(['api.ability:write']);
    Route::delete('/gitlab-apps/{gitlab_app_id}', [GitlabController::class, 'delete_gitlab_app'])->middleware(['api.ability:write']);

    Route::get('/databases', [DatabasesController::class, 'databases'])->middleware(['api.ability:read']);
    Route::post('/databases/postgresql', [DatabasesController::class, 'create_database_postgresql'])->middleware(['api.ability:write']);
    Route::post('/databases/mysql', [DatabasesController::class, 'create_database_mysql'])->middleware(['api.ability:write']);
    Route::post('/databases/mariadb', [DatabasesController::class, 'create_database_mariadb'])->middleware(['api.ability:write']);
    Route::post('/databases/mongodb', [DatabasesController::class, 'create_database_mongodb'])->middleware(['api.ability:write']);
    Route::post('/databases/redis', [DatabasesController::class, 'create_database_redis'])->middleware(['api.ability:write']);
    Route::post('/databases/clickhouse', [DatabasesController::class, 'create_database_clickhouse'])->middleware(['api.ability:write']);
    Route::post('/databases/dragonfly', [DatabasesController::class, 'create_database_dragonfly'])->middleware(['api.ability:write']);
    Route::post('/databases/keydb', [DatabasesController::class, 'create_database_keydb'])->middleware(['api.ability:write']);

    Route::get('/databases/{uuid}', [DatabasesController::class, 'database_by_uuid'])->middleware(['api.ability:read']);
    Route::get('/databases/{uuid}/backups', [DatabasesController::class, 'database_backup_details_uuid'])->middleware(['api.ability:read']);
    Route::get('/databases/{uuid}/backups/{scheduled_backup_uuid}/executions', [DatabasesController::class, 'list_backup_executions'])->middleware(['api.ability:read']);
    Route::patch('/databases/{uuid}', [DatabasesController::class, 'update_by_uuid'])->middleware(['api.ability:write']);
    Route::post('/databases/{uuid}/backups', [DatabasesController::class, 'create_backup'])->middleware(['api.ability:write']);
    Route::patch('/databases/{uuid}/backups/{scheduled_backup_uuid}', [DatabasesController::class, 'update_backup'])->middleware(['api.ability:write']);
    Route::delete('/databases/{uuid}', [DatabasesController::class, 'delete_by_uuid'])->middleware(['api.ability:write']);
    Route::get('/databases/{uuid}/logs', [DatabasesController::class, 'logs_by_uuid'])->middleware(['api.ability:read']);
    Route::delete('/databases/{uuid}/backups/{scheduled_backup_uuid}', [DatabasesController::class, 'delete_backup_by_uuid'])->middleware(['api.ability:write']);
    Route::delete('/databases/{uuid}/backups/{scheduled_backup_uuid}/executions/{execution_uuid}', [DatabasesController::class, 'delete_execution_by_uuid'])->middleware(['api.ability:write']);

    Route::get('/databases/{uuid}/storages', [DatabasesController::class, 'storages'])->middleware(['api.ability:read']);
    Route::post('/databases/{uuid}/storages', [DatabasesController::class, 'create_storage'])->middleware(['api.ability:write']);
    Route::patch('/databases/{uuid}/storages', [DatabasesController::class, 'update_storage'])->middleware(['api.ability:write']);
    Route::delete('/databases/{uuid}/storages/{storage_uuid}', [DatabasesController::class, 'delete_storage'])->middleware(['api.ability:write']);
    Route::put('/databases/{uuid}/storages/{storage_uuid}/backups', [VolumeBackupsController::class, 'upsert'])
        ->defaults('resource_type', 'database')
        ->middleware(['api.ability:write']);
    Route::post('/databases/{uuid}/storages/{storage_uuid}/backups/run', [VolumeBackupsController::class, 'run'])
        ->defaults('resource_type', 'database')
        ->middleware(['api.ability:write']);
    Route::delete('/databases/{uuid}/storages/{storage_uuid}/backups', [VolumeBackupsController::class, 'destroy'])
        ->defaults('resource_type', 'database')
        ->middleware(['api.ability:write']);

    Route::get('/databases/{uuid}/envs', [DatabasesController::class, 'envs'])->middleware(['api.ability:read']);
    Route::post('/databases/{uuid}/envs', [DatabasesController::class, 'create_env'])->middleware(['api.ability:write']);
    Route::patch('/databases/{uuid}/envs/bulk', [DatabasesController::class, 'create_bulk_envs'])->middleware(['api.ability:write']);
    Route::patch('/databases/{uuid}/envs', [DatabasesController::class, 'update_env_by_uuid'])->middleware(['api.ability:write']);
    Route::delete('/databases/{uuid}/envs/{env_uuid}', [DatabasesController::class, 'delete_env_by_uuid'])->middleware(['api.ability:write']);

    Route::get('/databases/{uuid}/tags', [DatabasesController::class, 'tags'])->middleware(['api.ability:read']);
    Route::post('/databases/{uuid}/tags', [DatabasesController::class, 'create_tag'])->middleware(['api.ability:write']);
    Route::delete('/databases/{uuid}/tags/{tag_uuid}', [DatabasesController::class, 'delete_tag'])->middleware(['api.ability:write']);

    Route::post('/databases/{uuid}/move', [DatabasesController::class, 'move_by_uuid'])->middleware(['api.ability:write']);
    Route::post('/databases/{uuid}/migrate', [DatabasesController::class, 'migrate_by_uuid'])->middleware(['api.ability:write']);
    Route::post('/databases/{uuid}/clone', [DatabasesController::class, 'clone_by_uuid'])->middleware(['api.ability:write']);
    Route::get('/databases/{uuid}/start', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/databases/{uuid}/restart', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/databases/{uuid}/stop', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::post('/databases/{uuid}/start', [DatabasesController::class, 'action_deploy'])->middleware(['api.ability:deploy']);
    Route::post('/databases/{uuid}/restart', [DatabasesController::class, 'action_restart'])->middleware(['api.ability:deploy']);
    Route::post('/databases/{uuid}/stop', [DatabasesController::class, 'action_stop'])->middleware(['api.ability:deploy']);

    Route::get('/services', [ServicesController::class, 'services'])->middleware(['api.ability:read']);
    Route::post('/services', [ServicesController::class, 'create_service'])->middleware(['api.ability:write']);

    Route::get('/services/{uuid}', [ServicesController::class, 'service_by_uuid'])->middleware(['api.ability:read']);
    Route::patch('/services/{uuid}', [ServicesController::class, 'update_by_uuid'])->middleware(['api.ability:write']);
    Route::delete('/services/{uuid}', [ServicesController::class, 'delete_by_uuid'])->middleware(['api.ability:write']);

    Route::get('/services/{uuid}/storages', [ServicesController::class, 'storages'])->middleware(['api.ability:read']);
    Route::post('/services/{uuid}/storages', [ServicesController::class, 'create_storage'])->middleware(['api.ability:write']);
    Route::patch('/services/{uuid}/storages', [ServicesController::class, 'update_storage'])->middleware(['api.ability:write']);
    Route::delete('/services/{uuid}/storages/{storage_uuid}', [ServicesController::class, 'delete_storage'])->middleware(['api.ability:write']);
    Route::put('/services/{uuid}/storages/{storage_uuid}/backups', [VolumeBackupsController::class, 'upsert'])
        ->defaults('resource_type', 'service')
        ->middleware(['api.ability:write']);
    Route::post('/services/{uuid}/storages/{storage_uuid}/backups/run', [VolumeBackupsController::class, 'run'])
        ->defaults('resource_type', 'service')
        ->middleware(['api.ability:write']);
    Route::delete('/services/{uuid}/storages/{storage_uuid}/backups', [VolumeBackupsController::class, 'destroy'])
        ->defaults('resource_type', 'service')
        ->middleware(['api.ability:write']);

    Route::get('/services/{uuid}/envs', [ServicesController::class, 'envs'])->middleware(['api.ability:read']);
    Route::post('/services/{uuid}/envs', [ServicesController::class, 'create_env'])->middleware(['api.ability:write']);
    Route::patch('/services/{uuid}/envs/bulk', [ServicesController::class, 'create_bulk_envs'])->middleware(['api.ability:write']);
    Route::patch('/services/{uuid}/envs', [ServicesController::class, 'update_env_by_uuid'])->middleware(['api.ability:write']);
    Route::delete('/services/{uuid}/envs/{env_uuid}', [ServicesController::class, 'delete_env_by_uuid'])->middleware(['api.ability:write']);
    Route::get('/services/{uuid}/logs', [ServicesController::class, 'logs_by_uuid'])->middleware(['api.ability:read']);

    Route::get('/services/{uuid}/tags', [ServicesController::class, 'tags'])->middleware(['api.ability:read']);
    Route::post('/services/{uuid}/tags', [ServicesController::class, 'create_tag'])->middleware(['api.ability:write']);
    Route::delete('/services/{uuid}/tags/{tag_uuid}', [ServicesController::class, 'delete_tag'])->middleware(['api.ability:write']);

    Route::post('/services/{uuid}/move', [ServicesController::class, 'move_by_uuid'])->middleware(['api.ability:write']);
    Route::post('/services/{uuid}/migrate', [ServicesController::class, 'migrate_by_uuid'])->middleware(['api.ability:write']);
    Route::post('/services/{uuid}/clone', [ServicesController::class, 'clone_by_uuid'])->middleware(['api.ability:write']);
    Route::get('/services/{uuid}/start', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/services/{uuid}/restart', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/services/{uuid}/stop', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/start', [ServicesController::class, 'action_deploy'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/restart', [ServicesController::class, 'action_restart'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/stop', [ServicesController::class, 'action_stop'])->middleware(['api.ability:deploy']);

    Route::get('/services/{uuid}/applications', [ServiceApplicationsController::class, 'index'])->middleware(['api.ability:read']);
    Route::get('/services/{uuid}/applications/{app_uuid}', [ServiceApplicationsController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/services/{uuid}/applications/{app_uuid}', [ServiceApplicationsController::class, 'update'])->middleware(['api.ability:write']);
    Route::match(['get', 'post'], '/services/{uuid}/applications/{app_uuid}/logs', [ServiceApplicationsController::class, 'logs_by_uuid'])->middleware(['api.ability:read']);
    Route::get('/services/{uuid}/applications/{app_uuid}/start', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/services/{uuid}/applications/{app_uuid}/restart', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::get('/services/{uuid}/applications/{app_uuid}/stop', [OtherController::class, 'post_required'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/applications/{app_uuid}/start', [ServiceApplicationsController::class, 'action_start'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/applications/{app_uuid}/restart', [ServiceApplicationsController::class, 'action_restart'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/applications/{app_uuid}/stop', [ServiceApplicationsController::class, 'action_stop'])->middleware(['api.ability:deploy']);

    Route::get('/services/{uuid}/databases', [ServiceDatabasesController::class, 'index'])->middleware(['api.ability:read']);
    Route::get('/services/{uuid}/databases/{database_uuid}', [ServiceDatabasesController::class, 'show'])->middleware(['api.ability:read']);
    Route::patch('/services/{uuid}/databases/{database_uuid}', [ServiceDatabasesController::class, 'update'])->middleware(['api.ability:write']);
    Route::get('/services/{uuid}/databases/{database_uuid}/logs', [ServiceDatabasesController::class, 'logs'])->middleware(['api.ability:read']);
    Route::post('/services/{uuid}/databases/{database_uuid}/start', [ServiceDatabasesController::class, 'start'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/databases/{database_uuid}/restart', [ServiceDatabasesController::class, 'restart'])->middleware(['api.ability:deploy']);
    Route::post('/services/{uuid}/databases/{database_uuid}/stop', [ServiceDatabasesController::class, 'stop'])->middleware(['api.ability:deploy']);

    Route::get('/applications/{uuid}/scheduled-tasks', [ScheduledTasksController::class, 'scheduled_tasks_by_application_uuid'])->middleware(['api.ability:read']);
    Route::post('/applications/{uuid}/scheduled-tasks', [ScheduledTasksController::class, 'create_scheduled_task_by_application_uuid'])->middleware(['api.ability:write']);
    Route::patch('/applications/{uuid}/scheduled-tasks/{task_uuid}', [ScheduledTasksController::class, 'update_scheduled_task_by_application_uuid'])->middleware(['api.ability:write']);
    Route::delete('/applications/{uuid}/scheduled-tasks/{task_uuid}', [ScheduledTasksController::class, 'delete_scheduled_task_by_application_uuid'])->middleware(['api.ability:write']);
    Route::get('/applications/{uuid}/scheduled-tasks/{task_uuid}/executions', [ScheduledTasksController::class, 'executions_by_application_uuid'])->middleware(['api.ability:read']);
    Route::post('/applications/{uuid}/scheduled-tasks/{task_uuid}/execute', [ScheduledTasksController::class, 'execute_scheduled_task_by_application_uuid'])->middleware(['api.ability:write']);

    Route::get('/services/{uuid}/scheduled-tasks', [ScheduledTasksController::class, 'scheduled_tasks_by_service_uuid'])->middleware(['api.ability:read']);
    Route::post('/services/{uuid}/scheduled-tasks', [ScheduledTasksController::class, 'create_scheduled_task_by_service_uuid'])->middleware(['api.ability:write']);
    Route::patch('/services/{uuid}/scheduled-tasks/{task_uuid}', [ScheduledTasksController::class, 'update_scheduled_task_by_service_uuid'])->middleware(['api.ability:write']);
    Route::delete('/services/{uuid}/scheduled-tasks/{task_uuid}', [ScheduledTasksController::class, 'delete_scheduled_task_by_service_uuid'])->middleware(['api.ability:write']);
    Route::get('/services/{uuid}/scheduled-tasks/{task_uuid}/executions', [ScheduledTasksController::class, 'executions_by_service_uuid'])->middleware(['api.ability:read']);
    Route::post('/services/{uuid}/scheduled-tasks/{task_uuid}/execute', [ScheduledTasksController::class, 'execute_scheduled_task_by_service_uuid'])->middleware(['api.ability:write']);
});

Route::group([
    'prefix' => 'v1',
], function () {
    Route::post('/sentinel/push', [SentinelController::class, 'push']);
});

Route::any('/{any}', function () {
    return response()->json(['message' => 'Not found.', 'docs' => 'https://coolify.io/docs'], 404);
})->where('any', '.*');
