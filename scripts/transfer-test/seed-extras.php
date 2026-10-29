<?php

// Fake records on the source (A) around development-qemu-debian-root, for extras-e2e.sh.
// Fake credentials are enough: the test checks that the records move, not that the providers accept them.

use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\ResourceLink;
use App\Models\SecretManagerLink;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMariadb;
use App\Services\ProjectIconStorageService;

$quiet = function (string $class, array $attributes) {
    $model = new $class;
    $model->forceFill(['uuid' => new_public_id(), ...$attributes])->saveQuietly();

    return $model;
};

$server = Server::where('uuid', 'development-qemu-debian-root')->firstOrFail();
$teamId = $server->team_id;
$app = Application::where('name', 'whoami-2')->firstOrFail();
$db = StandaloneMariadb::where('name', 'mariadb')->firstOrFail();
$environment = $app->environment;
$project = $environment->project;

// Team credentials
$cloudflare = $quiet(IntegrationToken::class, ['team_id' => $teamId, 'provider' => 'cloudflare', 'name' => 'fake-cloudflare', 'token' => 'fake-cf-token', 'capabilities' => ['dns', 'tunnel']]);
$infisical = $quiet(IntegrationToken::class, ['team_id' => $teamId, 'provider' => 'infisical', 'name' => 'fake-infisical', 'token' => 'fake-inf-token', 'capabilities' => ['secrets'], 'metadata' => ['client_id' => 'fake-client']]);

// DNS zone + owned record used only by whoami-2
$zone = $quiet(DnsProviderZone::class, ['integration_token_id' => $cloudflare->id, 'provider_zone_id' => 'fake-zone-id', 'name' => 'example.test', 'account_id' => 'fake-account', 'account_name' => 'Fake']);
$record = $quiet(ManagedDnsRecord::class, ['team_id' => $teamId, 'integration_token_id' => $cloudflare->id, 'dns_provider_zone_id' => $zone->id, 'provider_record_id' => 'fake-record-id', 'type' => 'A', 'name' => 'whoami2.example.test', 'content' => $server->ip, 'owned' => true]);
$record->addReference($app);

// Secret manager link
$quiet(SecretManagerLink::class, ['resourceable_type' => $app->getMorphClass(), 'resourceable_id' => $app->id, 'integration_token_id' => $infisical->id, 'settings' => ['project_id' => 'fake-project', 'environment' => 'prod', 'secret_path' => '/']]);

// Server: Cloudflare tunnel token + custom proxy error page
$server->settings->forceFill(['cloudflare_tunnel_integration_token_id' => $cloudflare->id, 'cloudflare_tunnel_id' => 'fake-tunnel', 'cloudflare_tunnel_account_id' => 'fake-account'])->saveQuietly();
$server->forceFill(['proxy_error_page' => '<h1>fake error page</h1>'])->saveQuietly();
// The server owns the DNS record of the SSH hostname of its tunnel
$quiet(ManagedDnsRecord::class, ['team_id' => $teamId, 'integration_token_id' => $cloudflare->id, 'dns_provider_zone_id' => $zone->id, 'provider_record_id' => 'fake-ssh-record-id', 'type' => 'CNAME', 'name' => 'ssh-debian.example.test', 'content' => 'fake-tunnel.cfargotunnel.com', 'owned' => true])->addReference($server);

// GitHub App + runner config (enabled) + one idle runner
$githubApp = $quiet(GithubApp::class, ['name' => 'fake-gh-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'organization' => 'fake-org', 'app_id' => 123, 'installation_id' => 456, 'client_id' => 'fake-client', 'client_secret' => 'fake-secret', 'webhook_secret' => 'fake-webhook', 'is_system_wide' => false, 'is_public' => false, 'team_id' => $teamId, 'private_key_id' => $server->private_key_id]);
$runnerConfig = $quiet(GithubRunnerConfig::class, ['server_id' => $server->id, 'github_app_id' => $githubApp->id, 'labels' => ['fake-label'], 'is_enabled' => true, 'max_runners' => 2]);
$quiet(GithubRunnerExecution::class, ['github_app_id' => $githubApp->id, 'github_runner_config_id' => $runnerConfig->id, 'server_id' => $server->id, 'status' => 'idle', 'trigger_workflow_job_id' => 1, 'runner_name' => 'coolify-runner-fake', 'runner_id' => 99, 'labels' => ['fake-label']]);

// A resource of the same project on the OTHER server: same environment (link that stays) and a staging environment
$ubuntu = Server::where('uuid', 'development-qemu-ubuntu-non-root')->firstOrFail();
$ubuntuDestination = StandaloneDocker::where('server_id', $ubuntu->id)->firstOrFail();
$onUbuntu = fn (Environment $env, string $name) => Application::create([
    'name' => $name, 'environment_id' => $env->id, 'destination_id' => $ubuntuDestination->id, 'destination_type' => $ubuntuDestination->getMorphClass(),
    'build_pack' => 'dockerimage', 'docker_registry_image_name' => 'traefik/whoami', 'docker_registry_image_tag' => 'latest', 'ports_exposes' => '80',
    'git_repository' => 'coollabsio/coolify', 'git_branch' => 'main',
]);
$otherServerApp = $onUbuntu($environment, 'fake-on-ubuntu');
$staging = Environment::firstOrCreate(['project_id' => $project->id, 'name' => 'fake-staging']);
$onUbuntu($staging, 'fake-staging-on-ubuntu');

// Resource links: one between two resources of this server, one to the other server
$quiet(ResourceLink::class, ['environment_id' => $environment->id, 'source_type' => $app->getMorphClass(), 'source_id' => $app->id, 'target_type' => $db->getMorphClass(), 'target_id' => $db->id, 'label' => 'uses db']);
$quiet(ResourceLink::class, ['environment_id' => $environment->id, 'source_type' => $app->getMorphClass(), 'source_id' => $app->id, 'target_type' => $otherServerApp->getMorphClass(), 'target_id' => $otherServerApp->id, 'label' => 'calls other']);

// Canvas positions, team shared variable used by an app variable, maintenance mode
$environment->forceFill(['canvas_positions' => [$app->uuid => ['x' => 111, 'y' => 222], $db->uuid => ['x' => 333, 'y' => 444]]])->saveQuietly();
SharedEnvironmentVariable::create(['type' => 'team', 'team_id' => $teamId, 'key' => 'FAKE_TEAM_VAR', 'value' => 'team-value']);
EnvironmentVariable::create(['key' => 'USES_TEAM', 'value' => '{{team.FAKE_TEAM_VAR}}', 'resourceable_type' => $app->getMorphClass(), 'resourceable_id' => $app->id]);
$app->forceFill(['is_maintenance_enabled' => true, 'maintenance_page' => '<h1>fake maintenance</h1>'])->saveQuietly();

// A git application that uses a system-wide GitHub App, which the transfer does not move
$systemApp = $quiet(GithubApp::class, ['name' => 'fake-system-wide-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'team_id' => 0, 'is_system_wide' => true, 'is_public' => false]);
$gitApp = Application::create([
    'name' => 'git-shop', 'build_pack' => 'nixpacks', 'git_repository' => 'acme/shop', 'git_branch' => 'main', 'ports_exposes' => '3000',
    'environment_id' => $environment->id, 'destination_id' => $app->destination_id, 'destination_type' => $app->destination_type,
]);
$gitApp->forceFill(['source_type' => GithubApp::class, 'source_id' => $systemApp->id])->saveQuietly();

// Project icon: a 1x1 JPEG
app(ProjectIconStorageService::class)->storeProjectContents($project, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));

echo "seeded\n";
