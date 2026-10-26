<?php

namespace App\Services\ServerTransfer;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\AuditEvent;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\DatabaseBackupS3Replica;
use App\Models\DiscordNotificationSettings;
use App\Models\DnsProviderZone;
use App\Models\DockerCleanupExecution;
use App\Models\EmailNotificationSettings;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ManagedDnsRecord;
use App\Models\ManagedDnsRecordReference;
use App\Models\NotificationThrottle;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\PersonalAccessToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ProjectSetting;
use App\Models\PushoverNotificationSettings;
use App\Models\ResourceLink;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\SecretManagerLink;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\SlackNotificationSettings;
use App\Models\SslCertificate;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Models\Subscription;
use App\Models\SwarmDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TelegramNotificationSettings;
use App\Models\User;
use App\Models\UserChangelogRead;
use App\Models\VolumeBackupS3Replica;
use App\Models\WebhookNotificationSettings;

/**
 * The decision for every model and every column: does a server transfer move it to the other instance?
 *
 * ServerTransferCoverageTest exports a server that has a record of every transferred model and fails when
 * a model is not listed here, or when a column of a transferred table is neither in the bundle nor in
 * NOT_EXPORTED_COLUMNS. So a new model or column gets a transfer decision when it is added.
 */
final class ServerTransferCoverage
{
    /**
     * Transferred models and where their records are in the bundle. `*` matches every list item. When a
     * record has an `attributes` object, its keys also count. `model` limits a path to one database class.
     *
     * @var array<class-string, list<string>>
     */
    public const TRANSFERRED = [
        Server::class => ['server'],
        ServerSetting::class => ['server.settings'],
        PrivateKey::class => ['private_key', 'private_keys.*'],
        StandaloneDocker::class => ['destinations.*'],
        SwarmDocker::class => ['destinations.*'],
        CloudProviderToken::class => ['cloud_provider_tokens.*'],
        IntegrationToken::class => ['integration_tokens.*'],
        DnsProviderZone::class => ['dns_zones.*'],
        ManagedDnsRecord::class => ['managed_dns_records.*'],
        ManagedDnsRecordReference::class => ['managed_dns_records.*.references.*'],
        SecretManagerLink::class => [
            'projects.*.environments.*.applications.*.secret_manager_link',
            'projects.*.environments.*.databases.*.secret_manager_link',
            'projects.*.environments.*.services.*.secret_manager_link',
        ],
        SslCertificate::class => ['ssl_certificates.*'],
        GithubApp::class => ['github_apps.*'],
        GithubRunnerConfig::class => ['github_runner_config'],
        GitlabApp::class => ['gitlab_apps.*'],
        GiteaApp::class => ['gitea_apps.*'],
        S3Storage::class => ['s3_storages.*'],
        Project::class => ['projects.*'],
        Environment::class => ['projects.*.environments.*'],
        ResourceLink::class => ['projects.*.environments.*.resource_links.*'],
        SharedEnvironmentVariable::class => [
            'shared_environment_variables.server.*',
            'shared_environment_variables.team.*',
            'projects.*.shared_environment_variables.*',
            'projects.*.environments.*.shared_environment_variables.*',
        ],
        Application::class => ['projects.*.environments.*.applications.*'],
        ApplicationSetting::class => ['projects.*.environments.*.applications.*.settings'],
        ApplicationPreview::class => ['projects.*.environments.*.applications.*.previews.*'],
        EnvironmentVariable::class => [
            'projects.*.environments.*.applications.*.environment_variables.*',
            'projects.*.environments.*.applications.*.environment_variables_preview.*',
            'projects.*.environments.*.databases.*.environment_variables.*',
            'projects.*.environments.*.services.*.environment_variables.*',
        ],
        LocalPersistentVolume::class => [
            'projects.*.environments.*.applications.*.persistent_storages.*',
            'projects.*.environments.*.databases.*.persistent_storages.*',
        ],
        LocalFileVolume::class => [
            'projects.*.environments.*.applications.*.file_storages.*',
            'projects.*.environments.*.databases.*.file_storages.*',
        ],
        ScheduledTask::class => [
            'projects.*.environments.*.applications.*.scheduled_tasks.*',
            'projects.*.environments.*.services.*.scheduled_tasks.*',
        ],
        Tag::class => [
            'projects.*.environments.*.applications.*.tags.*',
            'projects.*.environments.*.databases.*.tags.*',
            'projects.*.environments.*.services.*.tags.*',
        ],
        ScheduledDatabaseBackup::class => ['projects.*.environments.*.databases.*.scheduled_backups.*'],
        ScheduledVolumeBackup::class => ['volume_backups.*'],
        Service::class => ['projects.*.environments.*.services.*'],
        ServiceApplication::class => ['projects.*.environments.*.services.*.applications.*'],
        ServiceDatabase::class => ['projects.*.environments.*.services.*.databases.*'],
        StandalonePostgresql::class => ['projects.*.environments.*.databases.*'],
        StandaloneMysql::class => ['projects.*.environments.*.databases.*'],
        StandaloneMariadb::class => ['projects.*.environments.*.databases.*'],
        StandaloneMongodb::class => ['projects.*.environments.*.databases.*'],
        StandaloneRedis::class => ['projects.*.environments.*.databases.*'],
        StandaloneKeydb::class => ['projects.*.environments.*.databases.*'],
        StandaloneDragonfly::class => ['projects.*.environments.*.databases.*'],
        StandaloneClickhouse::class => ['projects.*.environments.*.databases.*'],
        StandaloneSqlite::class => ['projects.*.environments.*.databases.*'],
    ];

    private const LINKED = 'The bundle links the record by UUID or by its place in the bundle; the target sets its own ID.';

    private const TEAM = 'The target imports into one of its own teams.';

    private const RUNTIME = 'Runtime state: the target sets it again when it manages the server.';

    /**
     * Columns of transferred models that are not in the bundle under their own name, with the reason.
     * Columns in ALWAYS_NOT_EXPORTED are left out of every model.
     *
     * @var array<class-string, array<string, string>>
     */
    public const NOT_EXPORTED_COLUMNS = [
        Server::class => [
            'team_id' => self::TEAM, 'private_key_id' => self::LINKED, 'cloud_provider_token_id' => self::LINKED,
            'unreachable_count' => self::RUNTIME, 'high_disk_usage_notification_sent' => self::RUNTIME,
            'log_drain_notification_sent' => self::RUNTIME, 'validation_logs' => self::RUNTIME,
            'sentinel_updated_at' => self::RUNTIME, 'sentinel_waiting_since' => self::RUNTIME, 'ip_previous' => self::RUNTIME,
            'is_validating' => self::RUNTIME, 'detected_traefik_version' => self::RUNTIME, 'traefik_outdated_info' => self::RUNTIME,
            'hetzner_server_status' => self::RUNTIME, 'vultr_instance_status' => self::RUNTIME,
            'digitalocean_droplet_status' => self::RUNTIME, 'hostinger_virtual_machine_status' => self::RUNTIME,
            'server_metadata' => 'Facts about the host and the transfer state of the source; the target collects its own.',
            'canvas_position' => 'Place of the server on the servers canvas of the source; the target places it with its own servers.',
        ],
        ServerSetting::class => [
            'server_id' => self::LINKED, 'is_reachable' => self::RUNTIME, 'is_usable' => self::RUNTIME, 'force_disabled' => self::RUNTIME,
            'is_sentinel_enabled' => 'The target enables Sentinel when it claims the server.',
            'is_sentinel_debug_enabled' => 'The target enables Sentinel when it claims the server.',
            'sentinel_token' => 'The target makes a new Sentinel token when it claims the server.',
            'sentinel_custom_url' => 'The target sets its own URL when it claims the server.',
            'cloudflare_tunnel_integration_token_id' => 'Exported as cloudflare_tunnel_integration_token_uuid. '.self::LINKED,
            'patch_check_result' => self::RUNTIME, 'patch_check_error' => self::RUNTIME, 'patch_checked_at' => self::RUNTIME,
        ],
        PrivateKey::class => ['team_id' => self::TEAM],
        StandaloneDocker::class => ['server_id' => self::LINKED],
        SwarmDocker::class => ['server_id' => self::LINKED],
        CloudProviderToken::class => ['team_id' => self::TEAM],
        IntegrationToken::class => ['team_id' => self::TEAM],
        DnsProviderZone::class => ['integration_token_id' => self::LINKED],
        ManagedDnsRecord::class => ['team_id' => self::TEAM, 'integration_token_id' => self::LINKED, 'dns_provider_zone_id' => self::LINKED],
        ManagedDnsRecordReference::class => [
            'managed_dns_record_id' => 'The reference is part of its record in the bundle.',
            'resource_type' => self::LINKED, 'resource_id' => self::LINKED,
        ],
        SecretManagerLink::class => ['resourceable_type' => self::LINKED, 'resourceable_id' => self::LINKED, 'integration_token_id' => self::LINKED],
        SslCertificate::class => ['server_id' => self::LINKED, 'resource_type' => self::LINKED, 'resource_id' => self::LINKED],
        GithubApp::class => ['team_id' => self::TEAM, 'private_key_id' => self::LINKED],
        GithubRunnerConfig::class => [
            'server_id' => self::LINKED,
            'github_app_id' => 'Exported as github_app_uuid. '.self::LINKED,
        ],
        GitlabApp::class => ['team_id' => self::TEAM, 'private_key_id' => self::LINKED],
        GiteaApp::class => ['team_id' => self::TEAM, 'private_key_id' => self::LINKED],
        S3Storage::class => ['team_id' => self::TEAM, 'unusable_email_sent' => self::RUNTIME],
        Project::class => [
            'team_id' => self::TEAM,
            'icon_path' => 'The icon file is exported as `icon` (base64 content). The target stores it in its own icon storage and sets the path.',
            'icon_storage_type' => 'The target uses its own default icon storage. See icon_path.',
            'icon_s3_storage_id' => 'The target uses its own default icon storage. See icon_path.',
            'canvas_position' => 'Place of the project on the projects canvas of the source; the target places it with its own projects.',
        ],
        Environment::class => ['project_id' => self::LINKED],
        ResourceLink::class => [
            'environment_id' => 'The link is part of its environment in the bundle.',
            'source_type' => 'Exported as source.kind and source.uuid. '.self::LINKED,
            'source_id' => 'Exported as source.kind and source.uuid. '.self::LINKED,
            'target_type' => 'Exported as target.kind and target.uuid. '.self::LINKED,
            'target_id' => 'Exported as target.kind and target.uuid. '.self::LINKED,
            'created_by' => 'A user of the source instance; the target leaves it empty.',
        ],
        SharedEnvironmentVariable::class => [
            'type' => 'The scope follows from the place of the variable in the bundle.',
            'team_id' => self::TEAM, 'project_id' => self::LINKED, 'environment_id' => self::LINKED, 'server_id' => self::LINKED,
            'version' => 'The Coolify version that created the variable; the target sets its own.',
        ],
        Application::class => [
            'environment_id' => self::LINKED, 'destination_type' => self::LINKED, 'destination_id' => self::LINKED,
            'source_type' => self::LINKED, 'source_id' => self::LINKED, 'private_key_id' => self::LINKED,
        ],
        ApplicationSetting::class => ['application_id' => self::LINKED],
        ApplicationPreview::class => [
            'application_id' => self::LINKED, 'last_online_at' => self::RUNTIME, 'domain_dns_statuses' => self::RUNTIME,
            'restart_count' => self::RUNTIME, 'restart_limit_reached' => self::RUNTIME, 'last_restart_at' => self::RUNTIME,
            'last_restart_type' => self::RUNTIME,
        ],
        EnvironmentVariable::class => ['resourceable_type' => self::LINKED, 'resourceable_id' => self::LINKED],
        LocalPersistentVolume::class => [
            'resource_type' => self::LINKED, 'resource_id' => self::LINKED, 'standalone_sqlite_id' => self::LINKED,
            'container_id' => 'Not used: only listed in $fillable.',
        ],
        LocalFileVolume::class => ['resource_type' => self::LINKED, 'resource_id' => self::LINKED],
        ScheduledTask::class => ['team_id' => self::TEAM, 'application_id' => self::LINKED, 'service_id' => self::LINKED],
        Tag::class => ['team_id' => self::TEAM],
        ScheduledDatabaseBackup::class => [
            'team_id' => self::TEAM, 'database_type' => self::LINKED, 'database_id' => self::LINKED, 's3_storage_id' => self::LINKED,
            'last_execution_at' => 'Execution history of the source.',
        ],
        ScheduledVolumeBackup::class => [
            'team_id' => self::TEAM, 'backupable_type' => self::LINKED, 'backupable_id' => self::LINKED, 's3_storage_id' => self::LINKED,
        ],
        Service::class => [
            'environment_id' => self::LINKED, 'server_id' => self::LINKED, 'destination_type' => self::LINKED, 'destination_id' => self::LINKED,
        ],
        ServiceApplication::class => ['service_id' => self::LINKED],
        ServiceDatabase::class => ['service_id' => self::LINKED],
        StandalonePostgresql::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneMysql::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneMariadb::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneMongodb::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneRedis::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneKeydb::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneDragonfly::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneClickhouse::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
        ],
        StandaloneSqlite::class => [
            'destination_type' => self::LINKED, 'destination_id' => self::LINKED, 'environment_id' => self::LINKED,
            'config_hash' => 'Calculated again when the target starts the database.',
            'is_public' => 'Not used: SQLite has no public port.',
        ],
    ];

    /**
     * Created on the target instance for every record.
     *
     * @var list<string>
     */
    public const ALWAYS_NOT_EXPORTED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Models that a transfer does not move, with the reason.
     *
     * @var array<class-string, string>
     */
    public const NOT_TRANSFERRED = [
        Team::class => 'Instance-wide: the target imports into one of its own teams.',
        User::class => 'Instance-wide: users are not moved with a server.',
        TeamInvitation::class => 'Team data, not server data.',
        Subscription::class => 'Billing of the source instance.',
        InstanceSettings::class => 'Settings of the source instance.',
        OauthSetting::class => 'Settings of the source instance.',
        OauthIdentity::class => 'User login data of the source instance.',
        PersonalAccessToken::class => 'API tokens of the source instance.',
        UserChangelogRead::class => 'User data of the source instance.',
        AuditEvent::class => 'Audit log of the source instance.',
        NotificationThrottle::class => 'Runtime state of the source instance.',
        ScheduledJobState::class => 'Scheduler state of the source instance.',
        ScheduledJobDelivery::class => 'Scheduler state of the source instance.',
        EmailNotificationSettings::class => 'Team notification settings, not server data.',
        DiscordNotificationSettings::class => 'Team notification settings, not server data.',
        SlackNotificationSettings::class => 'Team notification settings, not server data.',
        TelegramNotificationSettings::class => 'Team notification settings, not server data.',
        PushoverNotificationSettings::class => 'Team notification settings, not server data.',
        WebhookNotificationSettings::class => 'Team notification settings, not server data.',
        CloudInitScript::class => 'Team server-creation templates, not server data.',
        ProjectSetting::class => 'Created with the project on the target; it has no settings of its own yet.',
        ApplicationDeploymentQueue::class => 'Deployment history.',
        ScheduledTaskExecution::class => 'Execution history.',
        ScheduledDatabaseBackupExecution::class => 'Execution history.',
        ScheduledVolumeBackupExecution::class => 'Execution history.',
        DatabaseBackupS3Replica::class => 'Backup history.',
        VolumeBackupS3Replica::class => 'Backup history.',
        DockerCleanupExecution::class => 'Execution history.',
        GithubRunnerExecution::class => 'Execution history. The source stops tracking the runners of the server when it is transferred.',
    ];
}
