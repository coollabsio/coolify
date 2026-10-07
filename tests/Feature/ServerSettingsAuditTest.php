<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Server\StartSentinel;
use App\Helpers\SslHelper;
use App\Livewire\Destination\New\Docker as NewDockerDestination;
use App\Livewire\Destination\Show as DestinationShow;
use App\Livewire\Project\Database\Postgresql\StatusInfo as PostgresqlStatusInfo;
use App\Livewire\Server\Advanced;
use App\Livewire\Server\CaCertificate\Show as CaCertificateShow;
use App\Livewire\Server\Charts;
use App\Livewire\Server\CloudflareTunnel;
use App\Livewire\Server\Destinations;
use App\Livewire\Server\DockerCleanup;
use App\Livewire\Server\GithubRunners;
use App\Livewire\Server\New\ByVultr;
use App\Livewire\Server\Proxy;
use App\Livewire\Server\Security\TerminalAccess;
use App\Livewire\Server\Sentinel;
use App\Livewire\Server\Show as ServerShow;
use App\Livewire\Server\Swarm;
use App\Models\AuditEvent;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Server::flushIdentityMap();
    Queue::fake();
    config(['constants.ssh.mux_enabled' => false]);

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * @return Collection<int, AuditEvent>
 */
function serverSettingsAuditRows(string $event): Collection
{
    return AuditEvent::query()->where('event', $event)->get();
}

function serverSettingsAuditVultrPrivateKey(): string
{
    return <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
}

function serverSettingsAuditGithubApp(Team $team): GithubApp
{
    $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsaKey, $pemKey);
    $privateKey = PrivateKey::create(['name' => 'Runner key', 'private_key' => $pemKey, 'team_id' => $team->id]);

    // Missing runner permissions, so saving does not call the GitHub API.
    return GithubApp::create([
        'name' => 'audit-runner-app',
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 4444,
        'installation_id' => 4445,
        'webhook_secret' => 'secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'organization_self_hosted_runners' => null,
        'actions' => null,
        'webhook_events' => ['push'],
    ]);
}

test('toggling terminal access records enabled and disabled events', function () {
    $this->server->settings->update(['is_terminal_enabled' => false]);

    $component = Livewire::test(TerminalAccess::class, ['server_uuid' => $this->server->uuid]);
    $component->call('toggleTerminal', 'password');
    $component->call('toggleTerminal', 'password');

    $enabled = serverSettingsAuditRows('ui.server.terminal_access.enabled');
    expect($enabled)->toHaveCount(1)
        ->and($enabled->first()->team_id)->toBe($this->team->id)
        ->and($enabled->first()->metadata['server_uuid'])->toBe($this->server->uuid)
        ->and(serverSettingsAuditRows('ui.server.terminal_access.disabled'))->toHaveCount(1);
});

test('server settings saves record changed setting names without the sentinel token', function () {
    $newToken = 'audit-secret-sentinel-token-123';

    Livewire::test(ServerShow::class, ['server_uuid' => $this->server->uuid])
        ->set('wildcardDomain', 'https://apps.example.com')
        ->set('sentinelToken', $newToken)
        ->call('submit')
        ->assertHasNoErrors();

    $rows = serverSettingsAuditRows('ui.server.settings_updated');
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->team_id)->toBe($this->team->id)
        ->and($rows->first()->metadata['changed_fields'])->toEqualCanonicalizing(['wildcard_domain', 'sentinel_token'])
        ->and(AuditEvent::query()->where('metadata', 'like', "%{$newToken}%")->exists())->toBeFalse();
});

test('saving server settings without changes records no event', function () {
    Livewire::test(ServerShow::class, ['server_uuid' => $this->server->uuid])
        ->call('submit')
        ->assertHasNoErrors();

    expect(serverSettingsAuditRows('ui.server.settings_updated'))->toBeEmpty();
});

test('cloudflare tunnel enable, disable and configuration record events without the tunnel token', function () {
    $component = Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid]);

    $component->call('manualCloudflareConfig');
    expect(serverSettingsAuditRows('ui.server.cloudflare_tunnel.enabled'))->toHaveCount(1);

    $component->call('toggleCloudflareTunnels');
    expect(serverSettingsAuditRows('ui.server.cloudflare_tunnel.disabled'))->toHaveCount(1)
        ->and($this->server->settings->fresh()->is_cloudflare_tunnel)->toBeFalsy();

    $component->set('cloudflare_token', 'cf-audit-secret-token')
        ->set('ssh_domain', 'ssh.example.com')
        ->call('automatedCloudflareConfig');

    $configured = serverSettingsAuditRows('ui.server.cloudflare_tunnel.configuration_started');
    expect($configured)->toHaveCount(1)
        ->and($configured->first()->metadata['ssh_domain'])->toBe('ssh.example.com')
        ->and(AuditEvent::query()->where('metadata', 'like', '%cf-audit-secret-token%')->exists())->toBeFalse();
});

test('docker cleanup settings record changed fields once and skip unchanged saves', function () {
    $component = Livewire::test(DockerCleanup::class, ['server_uuid' => $this->server->uuid])
        ->set('deleteUnusedVolumes', true)
        ->call('instantSave');

    $component->call('instantSave');

    $rows = serverSettingsAuditRows('ui.server.docker_cleanup.updated');
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->metadata['changed_fields'])->toBe(['delete_unused_volumes']);
});

test('sentinel instant save records the changed settings', function () {
    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->set('isSentinelDebugEnabled', true)
        ->call('instantSave');

    $rows = serverSettingsAuditRows('ui.server.sentinel.updated');
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->metadata['changed_fields'])->toBe(['is_sentinel_debug_enabled']);
});

test('metrics settings and the metrics toggle record sentinel updates', function () {
    StartSentinel::shouldRun();

    Livewire::test(Charts::class, ['server_uuid' => $this->server->uuid])
        ->set('sentinelMetricsHistoryDays', 30)
        ->call('saveMetricsSettings')
        ->call('toggleMetrics');

    $changedFields = serverSettingsAuditRows('ui.server.sentinel.updated')->pluck('metadata.changed_fields')->all();
    expect($changedFields)->toBe([['sentinel_metrics_history_days'], ['is_metrics_enabled']]);
});

test('advanced and swarm settings record server settings updates', function () {
    Livewire::test(Advanced::class, ['server_uuid' => $this->server->uuid])
        ->set('concurrentBuilds', 4)
        ->call('instantSave');

    $swarmServer = Server::factory()->create(['team_id' => $this->team->id]);
    $swarmServer->settings()->update(['is_swarm_manager' => true]);

    Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
        ->set('isSwarmWorker', true)
        ->call('instantSave');

    $changedFields = serverSettingsAuditRows('ui.server.settings_updated')->pluck('metadata.changed_fields')->all();
    expect($changedFields)->toBe([['concurrent_builds'], ['is_swarm_worker']]);
});

test('proxy settings and configuration saves record proxy events', function () {
    SaveProxyConfiguration::shouldRun();

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->set('generateExactLabels', true)
        ->call('instantSave')
        ->set('proxySettings', "services:\n  traefik:\n    image: traefik:v3.6\n")
        ->call('submit');

    $updated = serverSettingsAuditRows('ui.server.proxy.updated');
    expect($updated)->toHaveCount(1)
        ->and($updated->first()->metadata['changed_fields'])->toBe(['generate_exact_labels'])
        ->and(serverSettingsAuditRows('ui.server.proxy.configuration_saved'))->toHaveCount(1);
});

test('github runner settings record created, updated and disabled events', function () {
    $this->server->settings()->update(['server_role' => 'build', 'is_build_server' => true]);
    Server::flushIdentityMap();
    $githubApp = serverSettingsAuditGithubApp($this->team);

    $component = Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
        ->set('githubAppId', $githubApp->id)
        ->call('submit')
        ->assertHasNoErrors();

    $component->set('maxRunners', 5)->call('submit');
    $component->call('submit');

    GithubRunnerConfig::query()->update(['is_enabled' => true]);
    $component->call('toggleEnabled');

    $created = serverSettingsAuditRows('ui.server.github_runners.created');
    $updated = serverSettingsAuditRows('ui.server.github_runners.updated');
    expect($created)->toHaveCount(1)
        ->and($created->first()->metadata['github_app_uuid'])->toBe($githubApp->uuid)
        ->and($updated)->toHaveCount(1)
        ->and($updated->first()->metadata['changed_fields'])->toBe(['max_runners'])
        ->and(serverSettingsAuditRows('ui.server.github_runners.disabled'))->toHaveCount(1);
});

test('CA certificate save and regeneration record events without certificate contents', function () {
    Livewire::test(CaCertificateShow::class, ['server_uuid' => $this->server->uuid])
        ->call('regenerateCaCertificate')
        ->assertDispatched('success');

    $caCertificate = $this->server->sslCertificates()->where('is_ca_certificate', true)->firstOrFail();

    Livewire::test(CaCertificateShow::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', $caCertificate->ssl_certificate)
        ->call('saveCaCertificate')
        ->assertDispatched('success');

    expect(serverSettingsAuditRows('ui.server.ca_certificate.regenerated'))->toHaveCount(1)
        ->and(serverSettingsAuditRows('ui.server.ca_certificate.updated'))->toHaveCount(1)
        ->and(AuditEvent::query()->where('metadata', 'like', '%PRIVATE KEY%')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('metadata', 'like', '%BEGIN CERTIFICATE%')->exists())->toBeFalse();
});

test('regenerating a database SSL certificate records an event', function () {
    $this->server->generateCaCertificate();
    $caCertificate = $this->server->sslCertificates()->where('is_ca_certificate', true)->firstOrFail();
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandalonePostgresql::create([
        'name' => 'audit-ssl-db',
        'status' => 'exited:unhealthy',
        'enable_ssl' => true,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'image' => 'postgres:16-alpine',
    ]);

    SslHelper::generateSslCertificate(
        commonName: $database->uuid,
        resourceType: $database->getMorphClass(),
        resourceId: $database->id,
        serverId: $this->server->id,
        caCert: $caCertificate->ssl_certificate,
        caKey: $caCertificate->ssl_private_key,
        configurationDir: '/data/coolify/databases/'.$database->uuid,
        mountPath: '/var/lib/postgresql/certs',
    );

    Livewire::test(PostgresqlStatusInfo::class, ['database' => $database])
        ->call('regenerateSslCertificate')
        ->assertDispatched('success');

    $rows = serverSettingsAuditRows('ui.database.ssl_certificate_regenerated');
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->team_id)->toBe($this->team->id)
        ->and($rows->first()->metadata['database_uuid'])->toBe($database->uuid)
        ->and(AuditEvent::query()->where('metadata', 'like', '%PRIVATE KEY%')->exists())->toBeFalse();
});

test('destination create, rename and delete record destination events', function () {
    Livewire::test(NewDockerDestination::class, ['server_id' => (string) $this->server->id])
        ->set('network', 'audit-network')
        ->call('submit');

    Livewire::test(Destinations::class, ['server_uuid' => $this->server->uuid])
        ->call('add', 'scanned-network');

    $created = serverSettingsAuditRows('ui.destination.created');
    expect($created)->toHaveCount(2)
        ->and($created->pluck('metadata.destination_type')->unique()->all())->toBe(['standalone'])
        ->and($created->first()->team_id)->toBe($this->team->id);

    $swarmDestination = SwarmDocker::create([
        'name' => 'audit-swarm',
        'network' => 'audit-swarm-network',
        'server_id' => $this->server->id,
    ]);

    $component = Livewire::test(DestinationShow::class, ['destination_uuid' => $swarmDestination->uuid])
        ->call('submit');
    expect(serverSettingsAuditRows('ui.destination.updated'))->toBeEmpty();

    $component->set('name', 'audit-swarm-renamed')->call('submit');
    $component->call('delete');

    $updated = serverSettingsAuditRows('ui.destination.updated');
    $deleted = serverSettingsAuditRows('ui.destination.deleted');
    expect($updated)->toHaveCount(1)
        ->and($updated->first()->metadata['changed_fields'])->toBe(['name'])
        ->and($deleted)->toHaveCount(1)
        ->and($deleted->first()->metadata['destination_uuid'])->toBe($swarmDestination->uuid)
        ->and($deleted->first()->metadata['destination_type'])->toBe('swarm');
});

test('saving a cloud-init script from the server wizard records an event without the script', function () {
    $token = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'vultr',
        'token' => 'test-vultr-token',
        'name' => 'Test Vultr Token',
    ]);
    $privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Private Key',
        'private_key' => serverSettingsAuditVultrPrivateKey(),
    ]);

    Http::fake([
        'https://api.vultr.com/v2/regions*' => Http::response(['regions' => [['id' => 'ewr', 'city' => 'New Jersey', 'country' => 'US']], 'meta' => ['links' => ['next' => null]]], 200),
        'https://api.vultr.com/v2/plans*' => Http::response(['plans' => [['id' => 'vc2-1c-1gb', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'monthly_cost' => 6, 'locations' => ['ewr']]], 'meta' => ['links' => ['next' => null]]], 200),
        'https://api.vultr.com/v2/os*' => Http::response(['os' => [['id' => 2284, 'name' => 'Ubuntu 24.04 LTS x64']], 'meta' => ['links' => ['next' => null]]], 200),
        'https://api.vultr.com/v2/ssh-keys*' => Http::response(['ssh_keys' => [], 'meta' => ['links' => ['next' => null]]], 200),
        '*' => Http::response(['error' => 'unavailable'], 500),
    ]);

    $script = "#cloud-config\npackages:\n  - audit-secret-package";

    Livewire::test(ByVultr::class, ['selectedTokenUuid' => $token->uuid])
        ->set('server_name', 'audit-vultr-server')
        ->set('selected_region', 'ewr')
        ->set('selected_plan', 'vc2-1c-1gb')
        ->set('selected_os_id', 2284)
        ->set('private_key_id', $privateKey->id)
        ->set('cloud_init_script', $script)
        ->set('save_cloud_init_script', true)
        ->set('cloud_init_script_name', 'Audit script')
        ->call('submit');

    $savedScript = CloudInitScript::query()->where('name', 'Audit script')->firstOrFail();
    $rows = serverSettingsAuditRows('ui.cloud_init_script.created');
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->metadata['cloud_init_script_id'])->toBe($savedScript->id)
        ->and(AuditEvent::query()->where('metadata', 'like', '%audit-secret-package%')->exists())->toBeFalse();
});
