<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\ProjectSetting;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Subscription;
use App\Models\SwarmDocker;
use App\Models\Tag;
use App\Models\User;

it('keeps required mass-assignment attributes fillable for internal create flows', function (string $modelClass, array $expectedAttributes) {
    $model = new $modelClass;

    expect($model->getFillable())->toContain(...$expectedAttributes);
})->with([
    // Relationship/ownership keys
    [CloudProviderToken::class, ['team_id']],
    [Tag::class, ['team_id']],
    [Subscription::class, ['team_id']],
    [ScheduledTaskExecution::class, ['scheduled_task_id']],
    [ScheduledDatabaseBackupExecution::class, ['uuid', 'scheduled_database_backup_id']],
    [ScheduledDatabaseBackup::class, ['uuid', 'team_id']],
    [ScheduledTask::class, ['uuid', 'team_id', 'application_id', 'service_id']],
    [ServiceDatabase::class, ['service_id']],
    [ServiceApplication::class, ['service_id']],
    [ApplicationDeploymentQueue::class, ['docker_registry_image_tag']],
    [Project::class, ['team_id', 'uuid']],
    [Environment::class, ['project_id', 'uuid']],
    [ProjectSetting::class, ['project_id']],
    [ApplicationSetting::class, ['application_id']],
    [ServerSetting::class, ['server_id']],
    [SwarmDocker::class, ['server_id']],
    [StandaloneDocker::class, ['server_id']],
    [User::class, ['pending_email', 'email_change_code', 'email_change_code_expires_at']],
    [Server::class, ['ip_previous']],
    [GithubApp::class, ['team_id', 'private_key_id']],

    // Application/Service resource keys (including uuid for clone flows)
    [Application::class, ['uuid', 'environment_id', 'destination_id', 'destination_type', 'source_id', 'source_type', 'repository_project_id', 'private_key_id']],
    [ApplicationPreview::class, ['uuid', 'application_id']],
    [Service::class, ['uuid', 'environment_id', 'server_id', 'destination_id', 'destination_type']],

    // Standalone database resource keys (including uuid for clone flows)
    [StandalonePostgresql::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
    [StandaloneMysql::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
    [StandaloneMariadb::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
    [StandaloneMongodb::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
    [StandaloneRedis::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
    [StandaloneKeydb::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
    [StandaloneDragonfly::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
    [StandaloneClickhouse::class, ['uuid', 'destination_type', 'destination_id', 'environment_id']],
]);
