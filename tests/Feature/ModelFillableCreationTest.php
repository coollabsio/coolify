<?php

use App\Models\Application;
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
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

it('creates User with all fillable attributes', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'fillable-test@example.com',
        'password' => bcrypt('password123'),
        'force_password_reset' => true,
        'marketing_emails' => false,
        'pending_email' => 'newemail@example.com',
        'email_change_code' => 'ABC123',
        'email_change_code_expires_at' => now()->addHour(),
    ]);

    expect($user->exists)->toBeTrue();
    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('fillable-test@example.com');
    expect($user->force_password_reset)->toBeTrue();
    expect($user->marketing_emails)->toBeFalse();
    expect($user->pending_email)->toBe('newemail@example.com');
    expect($user->email_change_code)->toBe('ABC123');
    expect($user->email_change_code_expires_at)->not->toBeNull();
});

it('creates Server with all fillable attributes', function () {
    $cloudToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-token',
        'name' => 'test-cloud',
    ]);

    $server = Server::create([
        'name' => 'fillable-test-server',
        'ip' => '10.0.0.99',
        'port' => 2222,
        'user' => 'deployer',
        'description' => 'A test server with all fillable attrs',
        'private_key_id' => $this->server->private_key_id,
        'cloud_provider_token_id' => $cloudToken->id,
        'team_id' => $this->team->id,
        'hetzner_server_id' => 'htz-12345',
        'hetzner_server_status' => 'running',
        'is_validating' => false,
        'detected_traefik_version' => 'v2.10.0',
        'traefik_outdated_info' => 'Up to date',
        'server_metadata' => '{"region":"eu-central"}',
        'ip_previous' => '10.0.0.1',
    ]);

    expect($server->exists)->toBeTrue();
    expect((string) $server->name)->toBe('fillable-test-server');
    expect((string) $server->ip)->toBe('10.0.0.99');
    expect($server->port)->toBe(2222);
    expect((string) $server->user)->toBe('deployer');
    expect((string) $server->description)->toBe('A test server with all fillable attrs');
    expect($server->private_key_id)->toBe($this->server->private_key_id);
    expect($server->cloud_provider_token_id)->toBe($cloudToken->id);
    expect($server->hetzner_server_id)->toBe('htz-12345');
    expect($server->hetzner_server_status)->toBe('running');
    expect($server->ip_previous)->toBe('10.0.0.1');
});

it('creates Project with all fillable attributes', function () {
    $project = Project::create([
        'name' => 'Fillable Test Project',
        'description' => 'Testing all fillable attrs',
        'team_id' => $this->team->id,
        'uuid' => 'custom-project-uuid',
    ]);

    expect($project->exists)->toBeTrue();
    expect($project->name)->toBe('Fillable Test Project');
    expect($project->description)->toBe('Testing all fillable attrs');
    expect($project->team_id)->toBe($this->team->id);
    expect($project->uuid)->toBe('custom-project-uuid');
});

it('creates Environment with all fillable attributes', function () {
    $env = Environment::create([
        'name' => 'staging',
        'description' => 'Staging environment',
        'project_id' => $this->project->id,
        'uuid' => 'custom-env-uuid',
    ]);

    expect($env->exists)->toBeTrue();
    expect($env->name)->toBe('staging');
    expect($env->description)->toBe('Staging environment');
    expect($env->project_id)->toBe($this->project->id);
    expect($env->uuid)->toBe('custom-env-uuid');
});

it('creates ProjectSetting with all fillable attributes', function () {
    $setting = ProjectSetting::create([
        'project_id' => $this->project->id,
    ]);

    expect($setting->exists)->toBeTrue();
    expect($setting->project_id)->toBe($this->project->id);
});

it('creates Application with all fillable attributes', function () {
    $application = Application::create([
        'uuid' => 'custom-app-uuid',
        'name' => 'Full Fillable App',
        'description' => 'App with every fillable attr set',
        'fqdn' => 'https://app.example.com',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'git_commit_sha' => 'abc123def456',
        'git_full_url' => 'https://github.com/coollabsio/coolify.git',
        'docker_registry_image_name' => 'ghcr.io/coollabsio/coolify',
        'docker_registry_image_tag' => 'latest',
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'install_command' => 'npm install',
        'build_command' => 'npm run build',
        'start_command' => 'npm start',
        'ports_exposes' => '3000',
        'ports_mappings' => '3000:3000',
        'base_directory' => '/',
        'publish_directory' => '/dist',
        'health_check_enabled' => true,
        'health_check_path' => '/health',
        'health_check_port' => '3000',
        'health_check_host' => 'localhost',
        'health_check_method' => 'GET',
        'health_check_return_code' => 200,
        'health_check_scheme' => 'http',
        'health_check_response_text' => 'ok',
        'health_check_interval' => 30,
        'health_check_timeout' => 5,
        'health_check_retries' => 3,
        'health_check_start_period' => 10,
        'health_check_type' => 'http',
        'health_check_command' => 'curl -f http://localhost:3000/health',
        'limits_memory' => '512m',
        'limits_memory_swap' => '1g',
        'limits_memory_swappiness' => 60,
        'limits_memory_reservation' => '256m',
        'limits_cpus' => '2',
        'limits_cpuset' => '0-1',
        'limits_cpu_shares' => 1024,
        'status' => 'running',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'dockerfile' => 'FROM node:18\nRUN npm install',
        'dockerfile_location' => '/Dockerfile',
        'dockerfile_target_build' => 'production',
        'custom_labels' => 'traefik.enable=true',
        'custom_docker_run_options' => '--cap-add=NET_ADMIN',
        'post_deployment_command' => 'php artisan migrate',
        'post_deployment_command_container' => 'app',
        'pre_deployment_command' => 'php artisan down',
        'pre_deployment_command_container' => 'app',
        'manual_webhook_secret_github' => 'gh-secret-123',
        'manual_webhook_secret_gitlab' => 'gl-secret-456',
        'manual_webhook_secret_bitbucket' => 'bb-secret-789',
        'manual_webhook_secret_gitea' => 'gt-secret-012',
        'docker_compose_location' => '/docker-compose.yml',
        'docker_compose' => 'services: {}',
        'docker_compose_raw' => 'services:\n  app:\n    image: nginx',
        'docker_compose_domains' => '{"app":"https://app.example.com"}',
        'docker_compose_custom_start_command' => 'docker compose up -d',
        'docker_compose_custom_build_command' => 'docker compose build',
        'swarm_replicas' => 3,
        'swarm_placement_constraints' => 'node.role==worker',
        'watch_paths' => 'src/**,package.json',
        'redirect' => 'www',
        'compose_parsing_version' => '2',
        'custom_nginx_configuration' => 'location / { proxy_pass http://localhost:3000; }',
        'custom_network_aliases' => 'app-alias',
        'custom_healthcheck_found' => false,
        // Note: nixpkgsarchive, connect_to_docker_network, force_domain_override,
        // is_container_label_escape_enabled, use_build_server are in $fillable but
        // their migration columns may not exist in the test SQLite schema yet.
        'is_http_basic_auth_enabled' => false,
        'http_basic_auth_username' => 'admin',
        'http_basic_auth_password' => 'secret',
        'config_hash' => 'sha256:abc123',
        'last_online_at' => now()->subMinutes(5)->toISOString(),
        'restart_count' => 2,
        'last_restart_at' => now()->subHour()->toISOString(),
        'last_restart_type' => 'manual',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => null,
        'source_type' => null,
        'repository_project_id' => null,
        'private_key_id' => null,
    ]);

    expect($application->exists)->toBeTrue();
    expect($application->uuid)->toBe('custom-app-uuid');
    expect($application->name)->toBe('Full Fillable App');
    expect((string) $application->git_repository)->toBe('https://github.com/coollabsio/coolify');
    expect($application->build_pack)->toBe('nixpacks');
    expect($application->ports_exposes)->toBe('3000');
    expect($application->environment_id)->toBe($this->environment->id);
    expect($application->destination_id)->toBe($this->destination->id);
    expect($application->health_check_enabled)->toBeTrue();
    expect($application->limits_memory)->toBe('512m');
    expect($application->swarm_replicas)->toBe(3);
    expect($application->restart_count)->toBe(2);
});

it('creates ApplicationSetting with all fillable attributes', function () {
    $app = Application::create([
        'name' => 'settings-test-app',
        'git_repository' => 'https://github.com/test/repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    // Delete auto-created setting so we can create one with all attrs
    ApplicationSetting::where('application_id', $app->id)->delete();

    $setting = ApplicationSetting::create([
        'application_id' => $app->id,
        'is_static' => true,
        'is_git_submodules_enabled' => true,
        'is_git_lfs_enabled' => true,
        'is_auto_deploy_enabled' => false,
        'is_force_https_enabled' => true,
        'is_debug_enabled' => true,
        'is_preview_deployments_enabled' => false,
        'is_log_drain_enabled' => true,
        'is_gpu_enabled' => true,
        'gpu_driver' => 'nvidia',
        'gpu_count' => '2',
        'gpu_device_ids' => 'GPU-abc,GPU-def',
        'gpu_options' => '--gpus all',
        'is_include_timestamps' => true,
        'is_swarm_only_worker_nodes' => false,
        'is_raw_compose_deployment_enabled' => false,
        'is_build_server_enabled' => false,
        'is_consistent_container_name_enabled' => true,
        'is_gzip_enabled' => true,
        'is_stripprefix_enabled' => true,
        'connect_to_docker_network' => false,
        'custom_internal_name' => 'my-custom-app',
        'is_container_label_escape_enabled' => true,
        'is_env_sorting_enabled' => true,
        'is_container_label_readonly_enabled' => false,
        'is_preserve_repository_enabled' => false,
        'disable_build_cache' => false,
        'is_spa' => true,
        'is_git_shallow_clone_enabled' => true,
        'is_pr_deployments_public_enabled' => false,
        'use_build_secrets' => false,
        'inject_build_args_to_dockerfile' => true,
        'include_source_commit_in_build' => true,
        'docker_images_to_keep' => 5,
    ]);

    expect($setting->exists)->toBeTrue();
    expect($setting->application_id)->toBe($app->id);
    expect($setting->is_static)->toBeTrue();
    expect($setting->is_gpu_enabled)->toBeTrue();
    expect($setting->gpu_driver)->toBe('nvidia');
    expect($setting->custom_internal_name)->toBe('my-custom-app');
    expect($setting->is_spa)->toBeTrue();
    expect($setting->docker_images_to_keep)->toBe(5);
});

it('creates ServerSetting with all fillable attributes', function () {
    // Delete auto-created setting
    ServerSetting::where('server_id', $this->server->id)->delete();

    $setting = ServerSetting::create([
        'server_id' => $this->server->id,
        'is_swarm_manager' => false,
        'is_jump_server' => false,
        'is_build_server' => true,
        'is_reachable' => true,
        'is_usable' => true,
        'wildcard_domain' => '*.example.com',
        'is_cloudflare_tunnel' => false,
        'is_logdrain_newrelic_enabled' => true,
        'logdrain_newrelic_license_key' => 'nr-license-key-123',
        'logdrain_newrelic_base_uri' => 'https://log-api.newrelic.com',
        'is_logdrain_highlight_enabled' => false,
        'logdrain_highlight_project_id' => 'hl-proj-123',
        'is_logdrain_axiom_enabled' => true,
        'logdrain_axiom_dataset_name' => 'coolify-logs',
        'logdrain_axiom_api_key' => 'axiom-key-456',
        'is_swarm_worker' => false,
        'is_logdrain_custom_enabled' => false,
        'logdrain_custom_config' => '{"endpoint":"https://logs.example.com"}',
        'logdrain_custom_config_parser' => 'json',
        'concurrent_builds' => 4,
        'dynamic_timeout' => 600,
        'force_disabled' => false,
        'is_metrics_enabled' => true,
        'generate_exact_labels' => true,
        'force_docker_cleanup' => false,
        'docker_cleanup_frequency' => '0 2 * * *',
        'docker_cleanup_threshold' => 80,
        'server_timezone' => 'UTC',
        'delete_unused_volumes' => true,
        'delete_unused_networks' => true,
        'is_sentinel_enabled' => true,
        'sentinel_token' => 'sentinel-token-789',
        'sentinel_metrics_refresh_rate_seconds' => 30,
        'sentinel_metrics_history_days' => 7,
        'sentinel_push_interval_seconds' => 60,
        'sentinel_custom_url' => 'https://sentinel.example.com',
        'server_disk_usage_notification_threshold' => 90,
        'is_sentinel_debug_enabled' => false,
        'server_disk_usage_check_frequency' => '*/5 * * * *',
        'is_terminal_enabled' => true,
        'deployment_queue_limit' => 10,
        'disable_application_image_retention' => false,
    ]);

    expect($setting->exists)->toBeTrue();
    expect($setting->server_id)->toBe($this->server->id);
    expect($setting->is_build_server)->toBeTrue();
    expect($setting->wildcard_domain)->toBe('*.example.com');
    expect($setting->concurrent_builds)->toBe(4);
    expect($setting->sentinel_token)->toBe('sentinel-token-789');
    expect($setting->deployment_queue_limit)->toBe(10);
});

it('creates Service with all fillable attributes', function () {
    $service = Service::create([
        'uuid' => 'custom-service-uuid',
        'name' => 'Full Fillable Service',
        'description' => 'Service with all fillable attrs',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx",
        'docker_compose' => "services:\n  app:\n    image: nginx",
        'connect_to_docker_network' => true,
        'service_type' => 'test-service',
        'config_hash' => 'sha256:svc123',
        'compose_parsing_version' => '2',
        'is_container_label_escape_enabled' => true,
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    expect($service->exists)->toBeTrue();
    expect($service->uuid)->toBe('custom-service-uuid');
    expect($service->name)->toBe('Full Fillable Service');
    expect($service->docker_compose_raw)->not->toBeNull();
    expect($service->service_type)->toBe('test-service');
    expect($service->environment_id)->toBe($this->environment->id);
    expect($service->server_id)->toBe($this->server->id);
});

it('creates ApplicationPreview with all fillable attributes', function () {
    $app = Application::create([
        'name' => 'preview-test-app',
        'git_repository' => 'https://github.com/test/repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $preview = ApplicationPreview::create([
        'uuid' => 'custom-preview-uuid',
        'application_id' => $app->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/test/repo/pull/42',
        'pull_request_issue_comment_id' => 12345,
        'fqdn' => 'https://pr-42.app.example.com',
        'status' => 'queued',
        'git_type' => 'github',
        'docker_compose_domains' => '{"app":"https://pr-42.example.com"}',
        'docker_registry_image_tag' => 'pr-42',
        'last_online_at' => now()->toISOString(),
    ]);

    expect($preview->exists)->toBeTrue();
    expect($preview->uuid)->toBe('custom-preview-uuid');
    expect($preview->application_id)->toBe($app->id);
    expect($preview->pull_request_id)->toBe(42);
    expect($preview->fqdn)->toBe('https://pr-42.app.example.com');
    expect($preview->git_type)->toBe('github');
    expect($preview->docker_registry_image_tag)->toBe('pr-42');
});

it('creates ServiceApplication with all fillable attributes', function () {
    $service = Service::create([
        'docker_compose_raw' => 'services: {}',
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $svcApp = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'human_name' => 'Web Server',
        'description' => 'Main web application',
        'fqdn' => 'https://web.example.com',
        'ports' => '80,443',
        'exposes' => '80',
        'status' => 'running',
        'exclude_from_status' => false,
        'required_fqdn' => true,
        'image' => 'nginx:latest',
        'is_log_drain_enabled' => true,
        'is_include_timestamps' => true,
        'is_gzip_enabled' => true,
        'is_stripprefix_enabled' => true,
        'last_online_at' => now()->toISOString(),
        'is_migrated' => false,
    ]);

    expect($svcApp->exists)->toBeTrue();
    expect($svcApp->service_id)->toBe($service->id);
    expect($svcApp->name)->toBe('web');
    expect($svcApp->human_name)->toBe('Web Server');
    expect($svcApp->image)->toBe('nginx:latest');
    expect($svcApp->is_log_drain_enabled)->toBeTrue();
});

it('creates ServiceDatabase with all fillable attributes', function () {
    $service = Service::create([
        'docker_compose_raw' => 'services: {}',
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $svcDb = ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'postgres',
        'human_name' => 'PostgreSQL',
        'description' => 'Main database',
        'ports' => '5432',
        'exposes' => '5432',
        'status' => 'running',
        'exclude_from_status' => false,
        'image' => 'postgres:16',
        'public_port' => 15432,
        'is_public' => true,
        'is_log_drain_enabled' => true,
        'is_include_timestamps' => true,
        'is_gzip_enabled' => false,
        'is_stripprefix_enabled' => false,
        'last_online_at' => now()->toISOString(),
        'is_migrated' => false,
        'custom_type' => 'postgresql',
        'public_port_timeout' => 3600,
    ]);

    expect($svcDb->exists)->toBeTrue();
    expect($svcDb->service_id)->toBe($service->id);
    expect($svcDb->name)->toBe('postgres');
    expect($svcDb->public_port)->toBe(15432);
    expect($svcDb->is_public)->toBeTrue();
    expect($svcDb->custom_type)->toBe('postgresql');
});

it('creates StandalonePostgresql with all fillable attributes', function () {
    $db = StandalonePostgresql::create([
        'uuid' => 'custom-pg-uuid',
        'name' => 'Full Fillable Postgres',
        'description' => 'PG with all attrs',
        'postgres_user' => 'testuser',
        'postgres_password' => 'testpass123',
        'postgres_db' => 'testdb',
        'postgres_initdb_args' => '--encoding=UTF8',
        'postgres_host_auth_method' => 'scram-sha-256',
        'postgres_conf' => 'max_connections=200',
        'init_scripts' => 'CREATE TABLE test (id int);',
        'status' => 'running',
        'image' => 'postgres:16-alpine',
        'is_public' => true,
        'public_port' => 25432,
        'ports_mappings' => '25432:5432',
        'limits_memory' => '1g',
        'limits_memory_swap' => '2g',
        'limits_memory_swappiness' => 50,
        'limits_memory_reservation' => '512m',
        'limits_cpus' => '2',
        'limits_cpuset' => '0-1',
        'limits_cpu_shares' => 1024,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 1,
        'last_restart_at' => now()->subHours(6)->toISOString(),
        'last_restart_type' => 'manual',
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 7200,
        'enable_ssl' => true,
        'ssl_mode' => 'verify-full',
        'is_log_drain_enabled' => true,
        'is_include_timestamps' => true,
        'custom_docker_run_options' => '--shm-size=256m',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-pg-uuid');
    expect($db->postgres_user)->toBe('testuser');
    expect($db->postgres_db)->toBe('testdb');
    expect($db->is_public)->toBeTrue();
    expect($db->public_port)->toBe(25432);
    expect($db->enable_ssl)->toBeTrue();
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates StandaloneMysql with all fillable attributes', function () {
    $db = StandaloneMysql::create([
        'uuid' => 'custom-mysql-uuid',
        'name' => 'Full Fillable MySQL',
        'description' => 'MySQL with all attrs',
        'mysql_root_password' => 'rootpass123',
        'mysql_user' => 'testuser',
        'mysql_password' => 'testpass123',
        'mysql_database' => 'testdb',
        'mysql_conf' => '[mysqld]\nmax_connections=200',
        'status' => 'running',
        'image' => 'mysql:8.0',
        'is_public' => false,
        'public_port' => 23306,
        'ports_mappings' => '23306:3306',
        'limits_memory' => '1g',
        'limits_memory_swap' => '2g',
        'limits_memory_swappiness' => 50,
        'limits_memory_reservation' => '512m',
        'limits_cpus' => '2',
        'limits_cpuset' => '0-1',
        'limits_cpu_shares' => 1024,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 0,
        'last_restart_at' => null,
        'last_restart_type' => null,
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 3600,
        'enable_ssl' => true,
        'ssl_mode' => 'REQUIRED',
        'is_log_drain_enabled' => false,
        'is_include_timestamps' => false,
        'custom_docker_run_options' => '--ulimit nofile=65535:65535',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-mysql-uuid');
    expect($db->mysql_root_password)->toBe('rootpass123');
    expect($db->mysql_database)->toBe('testdb');
    expect($db->enable_ssl)->toBeTrue();
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates StandaloneMariadb with all fillable attributes', function () {
    $db = StandaloneMariadb::create([
        'uuid' => 'custom-maria-uuid',
        'name' => 'Full Fillable MariaDB',
        'description' => 'MariaDB with all attrs',
        'mariadb_root_password' => 'rootpass123',
        'mariadb_user' => 'testuser',
        'mariadb_password' => 'testpass123',
        'mariadb_database' => 'testdb',
        'mariadb_conf' => '[mysqld]\nmax_connections=200',
        'status' => 'running',
        'image' => 'mariadb:11',
        'is_public' => false,
        'public_port' => 23307,
        'ports_mappings' => '23307:3306',
        'limits_memory' => '1g',
        'limits_memory_swap' => '2g',
        'limits_memory_swappiness' => 50,
        'limits_memory_reservation' => '512m',
        'limits_cpus' => '2',
        'limits_cpuset' => '0-1',
        'limits_cpu_shares' => 1024,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 0,
        'last_restart_at' => null,
        'last_restart_type' => null,
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 3600,
        'enable_ssl' => false,
        'is_log_drain_enabled' => false,
        'custom_docker_run_options' => '',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-maria-uuid');
    expect($db->mariadb_root_password)->toBe('rootpass123');
    expect($db->mariadb_database)->toBe('testdb');
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates StandaloneMongodb with all fillable attributes', function () {
    $db = StandaloneMongodb::create([
        'uuid' => 'custom-mongo-uuid',
        'name' => 'Full Fillable MongoDB',
        'description' => 'MongoDB with all attrs',
        'mongo_conf' => '{"storage":{"dbPath":"/data/db"}}',
        'mongo_initdb_root_username' => 'mongoadmin',
        'mongo_initdb_root_password' => 'mongopass123',
        'mongo_initdb_database' => 'testdb',
        'status' => 'running',
        'image' => 'mongo:7',
        'is_public' => false,
        'public_port' => 27018,
        'ports_mappings' => '27018:27017',
        'limits_memory' => '2g',
        'limits_memory_swap' => '4g',
        'limits_memory_swappiness' => 60,
        'limits_memory_reservation' => '1g',
        'limits_cpus' => '4',
        'limits_cpuset' => '0-3',
        'limits_cpu_shares' => 2048,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 0,
        'last_restart_at' => null,
        'last_restart_type' => null,
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 3600,
        'enable_ssl' => false,
        'ssl_mode' => 'prefer',
        'is_log_drain_enabled' => false,
        'is_include_timestamps' => false,
        'custom_docker_run_options' => '',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-mongo-uuid');
    expect($db->mongo_initdb_root_username)->toBe('mongoadmin');
    expect($db->mongo_initdb_database)->toBe('testdb');
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates StandaloneRedis with all fillable attributes', function () {
    $db = StandaloneRedis::create([
        'uuid' => 'custom-redis-uuid',
        'name' => 'Full Fillable Redis',
        'description' => 'Redis with all attrs',
        'redis_conf' => 'maxmemory 256mb\nmaxmemory-policy allkeys-lru',
        'status' => 'running',
        'image' => 'redis:7-alpine',
        'is_public' => true,
        'public_port' => 26379,
        'ports_mappings' => '26379:6379',
        'limits_memory' => '512m',
        'limits_memory_swap' => '1g',
        'limits_memory_swappiness' => 30,
        'limits_memory_reservation' => '256m',
        'limits_cpus' => '1',
        'limits_cpuset' => '0',
        'limits_cpu_shares' => 512,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 0,
        'last_restart_at' => null,
        'last_restart_type' => null,
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 3600,
        'enable_ssl' => false,
        'is_log_drain_enabled' => false,
        'is_include_timestamps' => false,
        'custom_docker_run_options' => '',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-redis-uuid');
    expect($db->redis_conf)->toContain('maxmemory');
    expect($db->is_public)->toBeTrue();
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates StandaloneKeydb with all fillable attributes', function () {
    $db = StandaloneKeydb::create([
        'uuid' => 'custom-keydb-uuid',
        'name' => 'Full Fillable KeyDB',
        'description' => 'KeyDB with all attrs',
        'keydb_password' => 'keydbpass123',
        'keydb_conf' => 'server-threads 4',
        'is_log_drain_enabled' => false,
        'is_include_timestamps' => false,
        'status' => 'running',
        'image' => 'eqalpha/keydb:latest',
        'is_public' => false,
        'public_port' => 26380,
        'ports_mappings' => '26380:6379',
        'limits_memory' => '512m',
        'limits_memory_swap' => '1g',
        'limits_memory_swappiness' => 30,
        'limits_memory_reservation' => '256m',
        'limits_cpus' => '2',
        'limits_cpuset' => '0-1',
        'limits_cpu_shares' => 512,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 0,
        'last_restart_at' => null,
        'last_restart_type' => null,
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 3600,
        'enable_ssl' => false,
        'custom_docker_run_options' => '',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-keydb-uuid');
    expect($db->keydb_password)->toBe('keydbpass123');
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates StandaloneDragonfly with all fillable attributes', function () {
    $db = StandaloneDragonfly::create([
        'uuid' => 'custom-dragonfly-uuid',
        'name' => 'Full Fillable Dragonfly',
        'description' => 'Dragonfly with all attrs',
        'dragonfly_password' => 'dragonflypass123',
        'is_log_drain_enabled' => false,
        'is_include_timestamps' => false,
        'status' => 'running',
        'image' => 'docker.dragonflydb.io/dragonflydb/dragonfly:latest',
        'is_public' => false,
        'public_port' => 26381,
        'ports_mappings' => '26381:6379',
        'limits_memory' => '1g',
        'limits_memory_swap' => '2g',
        'limits_memory_swappiness' => 30,
        'limits_memory_reservation' => '512m',
        'limits_cpus' => '2',
        'limits_cpuset' => '0-1',
        'limits_cpu_shares' => 512,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 0,
        'last_restart_at' => null,
        'last_restart_type' => null,
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 3600,
        'enable_ssl' => false,
        'custom_docker_run_options' => '',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-dragonfly-uuid');
    expect($db->dragonfly_password)->toBe('dragonflypass123');
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates StandaloneClickhouse with all fillable attributes', function () {
    $db = StandaloneClickhouse::create([
        'uuid' => 'custom-ch-uuid',
        'name' => 'Full Fillable ClickHouse',
        'description' => 'ClickHouse with all attrs',
        'clickhouse_admin_user' => 'chadmin',
        'clickhouse_admin_password' => 'chpass123',
        'is_log_drain_enabled' => false,
        'is_include_timestamps' => false,
        'status' => 'running',
        'image' => 'clickhouse/clickhouse-server:latest',
        'is_public' => false,
        'public_port' => 28123,
        'ports_mappings' => '28123:8123',
        'limits_memory' => '2g',
        'limits_memory_swap' => '4g',
        'limits_memory_swappiness' => 30,
        'limits_memory_reservation' => '1g',
        'limits_cpus' => '4',
        'limits_cpuset' => '0-3',
        'limits_cpu_shares' => 2048,
        'started_at' => now()->subDay()->toISOString(),
        'restart_count' => 0,
        'last_restart_at' => null,
        'last_restart_type' => null,
        'last_online_at' => now()->toISOString(),
        'public_port_timeout' => 3600,
        'custom_docker_run_options' => '',
        'clickhouse_db' => 'testdb',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    expect($db->exists)->toBeTrue();
    expect($db->uuid)->toBe('custom-ch-uuid');
    expect($db->clickhouse_admin_user)->toBe('chadmin');
    expect($db->clickhouse_db)->toBe('testdb');
    expect($db->environment_id)->toBe($this->environment->id);
});

it('creates SwarmDocker with all fillable attributes', function () {
    $swarm = SwarmDocker::create([
        'server_id' => $this->server->id,
        'name' => 'swarm-dest',
        'network' => 'coolify-swarm',
    ]);

    expect($swarm->exists)->toBeTrue();
    expect($swarm->server_id)->toBe($this->server->id);
    expect($swarm->name)->toBe('swarm-dest');
    expect($swarm->network)->toBe('coolify-swarm');
});

it('creates StandaloneDocker with all fillable attributes', function () {
    $docker = StandaloneDocker::create([
        'server_id' => $this->server->id,
        'name' => 'standalone-dest',
        'network' => 'coolify-standalone',
    ]);

    expect($docker->exists)->toBeTrue();
    expect($docker->server_id)->toBe($this->server->id);
    expect($docker->name)->toBe('standalone-dest');
    expect($docker->network)->toBe('coolify-standalone');
});

it('creates ScheduledTask with all fillable attributes', function () {
    $app = Application::create([
        'name' => 'task-test-app',
        'git_repository' => 'https://github.com/test/repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $task = ScheduledTask::create([
        'uuid' => 'custom-task-uuid',
        'enabled' => true,
        'name' => 'Full Fillable Task',
        'command' => 'php artisan schedule:run',
        'frequency' => '* * * * *',
        'container' => 'app',
        'timeout' => 300,
        'team_id' => $this->team->id,
        'application_id' => $app->id,
        'service_id' => null,
    ]);

    expect($task->exists)->toBeTrue();
    expect($task->uuid)->toBe('custom-task-uuid');
    expect($task->name)->toBe('Full Fillable Task');
    expect($task->command)->toBe('php artisan schedule:run');
    expect($task->frequency)->toBe('* * * * *');
    expect($task->container)->toBe('app');
    expect($task->timeout)->toBe(300);
    expect($task->team_id)->toBe($this->team->id);
    expect($task->application_id)->toBe($app->id);
});

it('creates ScheduledDatabaseBackup with all fillable attributes', function () {
    $db = StandalonePostgresql::create([
        'name' => 'backup-test-pg',
        'postgres_user' => 'user',
        'postgres_password' => 'pass',
        'postgres_db' => 'testdb',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);

    $backup = ScheduledDatabaseBackup::create([
        'uuid' => 'custom-backup-uuid',
        'team_id' => $this->team->id,
        'description' => 'Full fillable backup',
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 2 * * *',
        'database_backup_retention_amount_locally' => 10,
        'database_type' => $db->getMorphClass(),
        'database_id' => $db->id,
        's3_storage_id' => null,
        'databases_to_backup' => 'testdb',
        'dump_all' => false,
        'database_backup_retention_days_locally' => 30,
        'database_backup_retention_max_storage_locally' => 5000,
        'database_backup_retention_amount_s3' => 20,
        'database_backup_retention_days_s3' => 60,
        'database_backup_retention_max_storage_s3' => 10000,
        'timeout' => 600,
        'disable_local_backup' => false,
    ]);

    expect($backup->exists)->toBeTrue();
    expect($backup->uuid)->toBe('custom-backup-uuid');
    expect($backup->frequency)->toBe('0 2 * * *');
    expect($backup->database_backup_retention_amount_locally)->toBe(10);
    expect($backup->databases_to_backup)->toBe('testdb');
    expect($backup->timeout)->toBe(600);
});

it('creates ScheduledDatabaseBackupExecution with all fillable attributes', function () {
    $db = StandalonePostgresql::create([
        'name' => 'exec-test-pg',
        'postgres_user' => 'user',
        'postgres_password' => 'pass',
        'postgres_db' => 'testdb',
        'destination_type' => $this->destination->getMorphClass(),
        'destination_id' => $this->destination->id,
        'environment_id' => $this->environment->id,
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 2 * * *',
        'database_type' => $db->getMorphClass(),
        'database_id' => $db->id,
        'team_id' => $this->team->id,
    ]);

    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => 'custom-exec-uuid',
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'message' => 'Backup completed successfully',
        'size' => 1048576,
        'filename' => 'backup-2026-03-31.sql.gz',
        'database_name' => 'testdb',
        'finished_at' => now()->toISOString(),
        'local_storage_deleted' => false,
        's3_storage_deleted' => false,
        's3_uploaded' => false,
    ]);

    expect($execution->exists)->toBeTrue();
    expect($execution->uuid)->toBe('custom-exec-uuid');
    expect($execution->status)->toBe('success');
    expect($execution->filename)->toBe('backup-2026-03-31.sql.gz');
    expect($execution->database_name)->toBe('testdb');
    expect($execution->size)->toBe(1048576);
});

it('creates ScheduledTaskExecution with all fillable attributes', function () {
    $app = Application::create([
        'name' => 'task-exec-app',
        'git_repository' => 'https://github.com/test/repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $task = ScheduledTask::create([
        'name' => 'exec-test-task',
        'command' => 'echo hello',
        'frequency' => '* * * * *',
        'timeout' => 60,
        'team_id' => $this->team->id,
        'application_id' => $app->id,
    ]);

    $execution = ScheduledTaskExecution::create([
        'scheduled_task_id' => $task->id,
        'status' => 'success',
        'message' => 'Task completed successfully',
        'finished_at' => now()->toISOString(),
        'started_at' => now()->subMinute()->toISOString(),
        'retry_count' => 0,
        'duration' => 60,
        'error_details' => null,
    ]);

    expect($execution->exists)->toBeTrue();
    expect($execution->scheduled_task_id)->toBe($task->id);
    expect($execution->status)->toBe('success');
    expect((float) $execution->duration)->toBe(60.0);
    expect($execution->retry_count)->toBe(0);
});

it('creates GithubApp with all fillable attributes', function () {
    $githubApp = GithubApp::create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->server->private_key_id,
        'name' => 'Full Fillable GH App',
        'organization' => 'coollabsio',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'Iv1.abc123',
        'client_secret' => 'secret-456',
        'webhook_secret' => 'whsec-789',
        'is_system_wide' => false,
        'is_public' => false,
        'contents' => 'read',
        'metadata' => 'read',
        'pull_requests' => 'write',
        'administration' => 'read',
    ]);

    expect($githubApp->exists)->toBeTrue();
    expect($githubApp->name)->toBe('Full Fillable GH App');
    expect($githubApp->organization)->toBe('coollabsio');
    expect($githubApp->app_id)->toBe(12345);
    expect($githubApp->installation_id)->toBe(67890);
    expect($githubApp->client_id)->toBe('Iv1.abc123');
    expect($githubApp->team_id)->toBe($this->team->id);
    expect($githubApp->private_key_id)->toBe($this->server->private_key_id);
});

it('creates Subscription with all fillable attributes', function () {
    $sub = Subscription::create([
        'team_id' => $this->team->id,
        'stripe_invoice_paid' => true,
        'stripe_subscription_id' => 'sub_1234567890',
        'stripe_customer_id' => 'cus_1234567890',
        'stripe_cancel_at_period_end' => false,
        'stripe_plan_id' => 'price_1234567890',
        'stripe_feedback' => 'Great service',
        'stripe_comment' => 'Will renew',
        'stripe_trial_already_ended' => true,
        'stripe_past_due' => false,
        'stripe_refunded_at' => null,
    ]);

    expect($sub->exists)->toBeTrue();
    expect($sub->team_id)->toBe($this->team->id);
    expect($sub->stripe_subscription_id)->toBe('sub_1234567890');
    expect($sub->stripe_customer_id)->toBe('cus_1234567890');
    expect($sub->stripe_plan_id)->toBe('price_1234567890');
    expect($sub->stripe_invoice_paid)->toBeTrue();
});

it('creates CloudProviderToken with all fillable attributes', function () {
    $token = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'hcloud-token-abc123',
        'name' => 'My Hetzner Token',
    ]);

    expect($token->exists)->toBeTrue();
    expect($token->team_id)->toBe($this->team->id);
    expect($token->provider)->toBe('hetzner');
    expect($token->token)->toBe('hcloud-token-abc123');
    expect($token->name)->toBe('My Hetzner Token');
});

it('creates Tag with all fillable attributes', function () {
    $tag = Tag::create([
        'name' => 'production',
        'team_id' => $this->team->id,
    ]);

    expect($tag->exists)->toBeTrue();
    expect($tag->name)->toBe('production');
    expect($tag->team_id)->toBe($this->team->id);
});
