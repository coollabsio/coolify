<?php

// Non-default values on development-qemu-debian-root of the source (A), for full-e2e.sh. Run after
// seed-extras.php and after full-e2e.sh created the resources through the API. Values that a transfer must keep
// are not the defaults, so a lost value shows up in the snapshot diff. Deployed resources keep working values.

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\GitlabApp;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandalonePostgresql;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerTransferFuzzer as Fuzzer;

$server = Server::where('uuid', 'development-qemu-debian-root')->firstOrFail();
$teamId = $server->team_id;
$ip = $server->ip;
$app = Application::where('name', 'whoami-2')->firstOrFail();
$environment = $app->environment;
$project = $environment->project;
$s3 = S3Storage::where('team_id', $teamId)->firstOrFail();

// 1. Server, proxy, and server settings
$server->forceFill(['description' => 'full coverage server'])->saveQuietly();
$server->proxy->set('redirect_enabled', true);
$server->proxy->set('redirect_url', 'https://redirect.example.test');
$server->saveQuietly();
$server->settings->forceFill([
    'wildcard_domain' => "http://{$ip}.sslip.io", 'concurrent_builds' => 3, 'dynamic_timeout' => 1800, 'deployment_queue_limit' => 5,
    'connection_timeout' => 20, 'server_timezone' => 'Europe/Budapest', 'force_docker_cleanup' => true, 'docker_cleanup_frequency' => '0 4 * * *',
    'docker_cleanup_threshold' => 70, 'delete_unused_volumes' => true, 'delete_unused_networks' => true,
    'server_disk_usage_notification_threshold' => 70, 'server_disk_usage_check_frequency' => '*/30 * * * *', 'server_disk_usage_notification_interval_hours' => 12,
    'patch_check_frequency' => 'daily', 'is_auto_security_updates_enabled' => true, 'auto_security_updates_time' => '04:30',
    'is_terminal_enabled' => false, 'disable_application_image_retention' => true, 'generate_exact_labels' => true,
    'traefik_certificate_resolver' => 'custom', 'backup_compression_cpu_percentage' => 50,
    'is_metrics_enabled' => true, 'sentinel_metrics_refresh_rate_seconds' => 10, 'sentinel_metrics_history_days' => 14, 'sentinel_push_interval_seconds' => 30,
    'is_traffic_analytics_enabled' => true, 'traffic_topn' => 20, 'traffic_sample_threshold' => 9, 'traffic_retention_1h_days' => 3, 'traffic_retention_1d_days' => 60,
    'traffic_ip_mode' => 'anonymized', 'is_geoip_enabled' => true, 'geoip_maxmind_license_key' => 'fake-maxmind-key', 'geoip_refresh_days' => 14,
    'logdrain_newrelic_license_key' => 'fake-newrelic', 'logdrain_newrelic_base_uri' => 'https://log-api.eu.newrelic.com/log/v1',
    'logdrain_highlight_project_id' => 'fake-highlight', 'logdrain_axiom_dataset_name' => 'fake-dataset', 'logdrain_axiom_api_key' => 'fake-axiom',
    'logdrain_custom_config' => "[OUTPUT]\n    Name stdout\n", 'logdrain_custom_config_parser' => "[PARSER]\n    Name fake\n",
    'logdrain_cloudwatch_region' => 'eu-central-1', 'logdrain_cloudwatch_group' => 'fake-group', 'logdrain_cloudwatch_stream_prefix' => 'fake-prefix',
])->saveQuietly();

// 2. Shared variables: server (multiline, literal, comment, empty), project, environment
$shared = fn (array $attributes) => SharedEnvironmentVariable::create(['team_id' => $teamId, ...$attributes]);
$shared(['type' => 'server', 'server_id' => $server->id, 'key' => 'SRV_MULTI', 'value' => "line one\n  line two\n", 'is_multiline' => true, 'comment' => 'multiline']);
$shared(['type' => 'server', 'server_id' => $server->id, 'key' => 'SRV_LITERAL', 'value' => '$NOT_EXPANDED', 'is_literal' => true]);
$shared(['type' => 'server', 'server_id' => $server->id, 'key' => 'SRV_EMPTY', 'value' => '']);
$shared(['type' => 'project', 'project_id' => $project->id, 'key' => 'PRJ_KEY', 'value' => 'project-value', 'is_shown_once' => true]);
$shared(['type' => 'environment', 'environment_id' => $environment->id, 'key' => 'ENV_KEY', 'value' => 'environment-value']);

// 3. whoami-2 (deployed): domains, health check, limits, advanced options, settings
$app->forceFill([
    'fqdn' => "http://whoami2.{$ip}.sslip.io,http://whoami2b.{$ip}.sslip.io", 'noindex_domains' => ["http://whoami2b.{$ip}.sslip.io"],
    'description' => 'full coverage app', 'ports_mappings' => '18080:80', 'preview_url_template' => 'pr-{{pr_id}}.{{domain}}', 'redirect' => 'non-www',
    'health_check_enabled' => true, 'health_check_path' => '/health', 'health_check_port' => '80', 'health_check_interval' => 7,
    'health_check_timeout' => 4, 'health_check_retries' => 3, 'health_check_start_period' => 9,
    'limits_memory' => '128m', 'limits_memory_swap' => '256m', 'limits_memory_swappiness' => 30, 'limits_memory_reservation' => '64m',
    'limits_cpus' => '0.5', 'limits_cpuset' => '0', 'limits_cpu_shares' => 512,
    'custom_docker_run_options' => '--cap-add=NET_ADMIN --ulimit nofile=1024:2048', 'custom_network_aliases' => 'whoami-alias',
    'watch_paths' => "src/**\ndocs/*.md",
    'manual_webhook_secret_github' => 'fake-gh-secret', 'is_http_basic_auth_enabled' => true, 'http_basic_auth_username' => 'fuzz',
    'http_basic_auth_password' => 'fuzz-password', 'max_restart_count' => 4,
])->save();
$app->settings->forceFill([
    'is_gzip_enabled' => false, 'is_stripprefix_enabled' => false, 'is_include_timestamps' => true, 'is_container_label_escape_enabled' => false,
    'is_env_sorting_enabled' => true, 'docker_images_to_keep' => 5, 'stop_grace_period' => 30, 'redact_all_env_values_in_logs' => true,
    'include_coolify_default_variables' => false, 'traefik_certificate_resolver' => 'custom', 'connect_to_docker_network' => true,
    'custom_internal_name' => 'whoami-two', 'is_permanent_redirect_enabled' => true, 'is_log_drain_enabled' => false,
    'is_preview_deployments_enabled' => true, 'is_pr_deployments_public_enabled' => true, 'is_auto_deploy_enabled' => false,
])->save();

// Variables of every kind, and references to every shared scope
$env = fn (array $attributes) => EnvironmentVariable::create(['resourceable_type' => $app->getMorphClass(), 'resourceable_id' => $app->id, ...$attributes]);
$env(['key' => 'MULTI_LINE', 'value' => "first\nsecond", 'is_multiline' => true, 'comment' => 'two lines']);
$env(['key' => 'LITERAL_VAR', 'value' => '$HOME/not-expanded', 'is_literal' => true]);
$env(['key' => 'SHOWN_ONCE', 'value' => 'only-once', 'is_shown_once' => true]);
$env(['key' => 'BUILD_ONLY', 'value' => 'build', 'is_runtime' => false, 'is_buildtime' => true]);
$env(['key' => 'RUNTIME_ONLY', 'value' => 'runtime', 'is_runtime' => true, 'is_buildtime' => false]);
$env(['key' => 'REQUIRED_VAR', 'value' => 'required', 'is_required' => true]);
$env(['key' => 'PREVIEW_ONLY', 'value' => 'preview', 'is_preview' => true]);
$env(['key' => 'USES_PROJECT', 'value' => '{{project.PRJ_KEY}}']);
$env(['key' => 'USES_ENVIRONMENT', 'value' => '{{environment.ENV_KEY}}']);
$env(['key' => 'USES_SERVER', 'value' => '{{server.SRV_LITERAL}}']);

// Storages: file mount with whitespace, directory mount, host bind mount, persistent volume with an S3 backup
$file = fn (array $attributes) => LocalFileVolume::create(['resource_type' => $app->getMorphClass(), 'resource_id' => $app->id, ...$attributes]);
$file(['fs_path' => "/data/coolify/applications/{$app->uuid}/etc/fuzz.conf", 'mount_path' => '/etc/fuzz.conf', 'content' => "  indented: true\nlast: line\n", 'chown' => '1000:1000', 'chmod' => '0640']);
$file(['fs_path' => "/data/coolify/applications/{$app->uuid}/fuzz-dir", 'mount_path' => '/fuzz-dir', 'is_directory' => true]);
LocalPersistentVolume::create(['name' => 'host-bind', 'mount_path' => '/host', 'host_path' => '/srv/fuzz-host', 'resource_type' => $app->getMorphClass(), 'resource_id' => $app->id]);
$volume = LocalPersistentVolume::create(['name' => "{$app->uuid}-data", 'mount_path' => '/data', 'is_preview_suffix_enabled' => true, 'resource_type' => $app->getMorphClass(), 'resource_id' => $app->id]);
$volumeBackup = ScheduledVolumeBackup::create([
    'backupable_type' => $volume->getMorphClass(), 'backupable_id' => $volume->id, 'team_id' => $teamId, 's3_storage_id' => $s3->id,
    'frequency' => '0 5 * * *', 'enabled' => true, 'save_s3' => true, 'stop_during_backup' => true, 'retention_amount_locally' => 3,
    'retention_days_locally' => 4, 'retention_max_storage_locally' => 5, 'retention_amount_s3' => 6, 'retention_days_s3' => 7,
    'retention_max_storage_s3' => 8, 'timeout' => 900, 'missing_backup_notification_days' => 2,
]);
$volumeBackup->s3Storages()->sync([$s3->id]);
ScheduledTask::create(['name' => 'disabled-task', 'command' => 'echo disabled', 'frequency' => '0 1 * * *', 'enabled' => false, 'container' => 'whoami', 'timeout' => 120, 'application_id' => $app->id, 'team_id' => $teamId]);

// 4. PostgreSQL with SSL (started): init script, config, public port, limits, health check, backups to S3
$pg = StandalonePostgresql::where('name', 'pg-ssl')->firstOrFail();
$pg->forceFill([
    'description' => 'full coverage postgres', 'image' => 'postgres:17-alpine', 'postgres_initdb_args' => '--data-checksums', 'postgres_host_auth_method' => 'scram-sha-256',
    'init_scripts' => [['index' => 0, 'filename' => 'init.sql', 'content' => "CREATE TABLE IF NOT EXISTS fuzz (id int);\n"]],
    'postgres_conf' => "max_connections = 50\n", 'enable_ssl' => true, 'ssl_mode' => 'verify-full', 'is_public' => true, 'public_port' => 25432,
    'public_port_timeout' => 1800, 'limits_memory' => '256m', 'limits_cpus' => '0.5', 'custom_docker_run_options' => '--shm-size=128m',
    'health_check_interval' => 11, 'health_check_timeout' => 6, 'health_check_retries' => 4, 'health_check_start_period' => 12,
    'is_include_timestamps' => true, 'redact_all_env_values_in_logs' => true, 'include_coolify_default_variables' => false,
])->save();
EnvironmentVariable::create(['key' => 'PG_EXTRA', 'value' => 'pg-extra', 'resourceable_type' => $pg->getMorphClass(), 'resourceable_id' => $pg->id]);
$pg->tags()->attach(Tag::firstOrCreate(['name' => 'database', 'team_id' => $teamId])->id);
$backup = ScheduledDatabaseBackup::create([
    'team_id' => $teamId, 'database_type' => $pg->getMorphClass(), 'database_id' => $pg->id, 's3_storage_id' => $s3->id, 'description' => 'full coverage backup',
    'enabled' => true, 'save_s3' => true, 'frequency' => '0 6 * * *', 'databases_to_backup' => 'postgres', 'dump_all' => false,
    'database_backup_retention_amount_locally' => 3, 'database_backup_retention_days_locally' => 4, 'database_backup_retention_max_storage_locally' => 5,
    'database_backup_retention_amount_s3' => 6, 'database_backup_retention_days_s3' => 7, 'database_backup_retention_max_storage_s3' => 8,
    'timeout' => 1200, 'disable_local_backup' => false, 'missing_backup_notification_days' => 2,
]);
$backup->s3Storages()->sync([$s3->id]);

// 5. Legacy service (parser version 3) with a ./ bind mount, service app and database settings
$service = Service::where('name', 'svc-legacy')->firstOrFail();
$service->forceFill([
    'compose_parsing_version' => '3', 'description' => 'parser v3 service', 'connect_to_docker_network' => true, 'is_container_label_escape_enabled' => false,
    'redact_all_env_values_in_logs' => true, 'include_coolify_default_variables' => false,
])->saveQuietly();
$serviceApp = $service->applications()->firstOrFail();
$serviceApp->forceFill([
    'fqdn' => "http://svc-legacy.{$ip}.sslip.io", 'is_gzip_enabled' => false, 'is_stripprefix_enabled' => false, 'exclude_from_status' => true,
    'redirect' => 'non-www', 'traefik_certificate_resolver' => 'custom', 'human_name' => 'Legacy Web', 'description' => 'service app',
])->save();
$serviceDb = $service->databases()->firstOrFail();
$serviceDb->forceFill(['public_port' => 26379, 'is_public' => false, 'custom_type' => 'redis', 'human_name' => 'Legacy Cache'])->save();
EnvironmentVariable::create(['key' => 'SERVICE_EXTRA', 'value' => 'service-extra', 'resourceable_type' => $service->getMorphClass(), 'resourceable_id' => $service->id]);
ScheduledTask::create(['name' => 'service-task', 'command' => 'echo service', 'frequency' => '0 2 * * *', 'container' => 'cache', 'service_id' => $service->id, 'team_id' => $teamId]);
$service->tags()->attach(Tag::firstOrCreate(['name' => 'legacy', 'team_id' => $teamId])->id);

// 6. Records that are not deployed: git sources, deploy key, preview. These and the databases that are not started get
// a non-default value in every column (Tests\Support\ServerTransferFuzzer), except the columns that must stay valid.
$deployKey = PrivateKey::create(['team_id' => $teamId, 'name' => 'fake deploy key', 'private_key' => generateSSHKey('ed25519')['private'], 'is_git_related' => true]);
$gitlab = new GitlabApp;
$gitlab->forceFill(['uuid' => new_public_id(), 'name' => 'fake-gitlab', 'api_url' => 'https://gitlab.example.test/api/v4', 'html_url' => 'https://gitlab.example.test',
    'is_system_wide' => false, 'team_id' => $teamId, 'private_key_id' => $deployKey->id])->saveQuietly();
Fuzzer::fill($gitlab, ['is_system_wide', 'api_url', 'html_url']);
$keepApplication = ['name', 'fqdn', 'build_pack', 'git_repository', 'status', 'docker_compose_raw', 'docker_compose', 'docker_compose_domains', 'domain_port_overrides',
    'noindex_domains', 'custom_labels', 'domain_dns_statuses', 'dockerfile', 'custom_nginx_configuration', 'watch_paths', 'custom_network_aliases'];
foreach ([['deploykey-app', 'nixpacks', null, $deployKey->id, 'git@github.com:acme/private.git'], ['gitlab-app', 'nixpacks', $gitlab, null, 'acme/gitlab-repo'], ['static-site', 'static', null, null, 'acme/static-site']] as [$name, $buildPack, $source, $keyId, $repo]) {
    $git = Application::create(['name' => $name, 'build_pack' => $buildPack, 'git_repository' => $repo, 'git_branch' => 'main', 'ports_exposes' => '3000',
        'environment_id' => $environment->id, 'destination_id' => $app->destination_id, 'destination_type' => $app->destination_type]);
    $git->forceFill(['source_type' => $source ? $source::class : null, 'source_id' => $source?->id, 'private_key_id' => $keyId])->saveQuietly();
    Fuzzer::fill($git, $keepApplication, ['compose_parsing_version' => '2', 'fqdn' => "http://{$name}.{$ip}.sslip.io", 'redirect' => 'www']);
    Fuzzer::fill($git->settings);
}
// The is_static setter sets port 80; the user can set another port after that.
Application::where('name', 'static-site')->firstOrFail()->forceFill(['ports_exposes' => '8080'])->saveQuietly();
// Keys that only older Coolify versions accepted. Deployments skip them with a warning.
$legacy = EnvironmentVariable::create(['key' => 'LEGACY_KEY', 'value' => 'legacy', 'resourceable_type' => $app->getMorphClass(), 'resourceable_id' => Application::where('name', 'static-site')->value('id')]);
DB::table('environment_variables')->where('id', $legacy->id)->update(['key' => 'legacy-key']);
$legacyShared = $shared(['type' => 'server', 'server_id' => $server->id, 'key' => 'LEGACY_SHARED', 'value' => 'legacy']);
DB::table('shared_environment_variables')->where('id', $legacyShared->id)->update(['key' => 'legacy-shared']);
$preview = new ApplicationPreview;
$preview->forceFill(['application_id' => $app->id, 'pull_request_id' => 42, 'pull_request_html_url' => 'https://github.com/acme/shop/pull/42',
    'fqdn' => "http://pr-42.whoami2.{$ip}.sslip.io", 'max_restart_count' => 3, 'docker_registry_image_tag' => 'pr-42'])->save();
foreach (['StandaloneMysql', 'StandaloneMongodb', 'StandaloneKeydb', 'StandaloneDragonfly', 'StandaloneClickhouse', 'StandaloneRedis', 'StandaloneSqlite'] as $type) {
    foreach (("App\\Models\\{$type}")::where('name', 'like', 'idle-%')->get() as $database) {
        // ssl_mode has a check constraint; pg-ssl covers it.
        Fuzzer::fill($database, ['status', 'is_public', 'public_port', 'init_scripts', 'sqlite_databases', 'ssl_mode'], ['description' => "idle {$type}"]);
    }
}

echo "seeded full coverage\n";
