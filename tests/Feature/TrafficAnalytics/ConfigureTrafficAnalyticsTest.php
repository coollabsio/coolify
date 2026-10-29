<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Actions\Server\StartSentinel;
use App\Jobs\RestartProxyJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = $user->teams()->first();
});

it('enables analytics without replacing custom proxy configuration', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->atLeast()->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn(<<<'YAML'
services:
  traefik:
    image: traefik:v3.7
    env_file:
      - .env
    command:
      - '--providers.docker=true'
YAML);
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once()->withArgs(
        fn (Server $server, string $configuration): bool => str_contains($configuration, 'traefik:v3.7')
            && str_contains($configuration, 'env_file:')
            && str_contains($configuration, '--accesslog=true')
    );

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    ConfigureTrafficAnalytics::run($server, true);

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
    Queue::assertPushed(RestartProxyJob::class);
});

it('disables analytics', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->atLeast()->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->twice()->andReturn("services:\n  traefik:\n    command: []\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->twice();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    ConfigureTrafficAnalytics::run($server, true);
    ConfigureTrafficAnalytics::run($server, false);

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});

function trafficAnalyticsProxyServer(object $test, string $proxyType, array $proxy = [], bool $analyticsEnabled = false): Server
{
    $server = Server::factory()->create(['team_id' => $test->team->id]);
    $server->proxy->set('type', $proxyType);
    foreach ($proxy as $key => $value) {
        $server->proxy->set($key, $value);
    }
    $server->save();
    $server->settings->is_traffic_analytics_enabled = $analyticsEnabled;
    $server->settings->save();

    return $server->fresh();
}

it('rejects enabling analytics when the server has no traefik or caddy proxy', function (string $proxyType) {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->never();
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->never();

    $server = trafficAnalyticsProxyServer($this, $proxyType);

    expect(fn () => ConfigureTrafficAnalytics::run($server, true))
        ->toThrow(RuntimeException::class, 'Traffic analytics needs the Traefik or Caddy proxy.');

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse()
        ->and($server->isTrafficAnalyticsEnabled())->toBeFalse()
        ->and($server->fresh()->proxy->get('last_saved_proxy_configuration'))->toBeNull();
    Queue::assertNotPushed(RestartProxyJob::class);
})->with(['NONE', 'NGINX']);

it('lets a server without a usable proxy turn analytics off', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->once();
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->never();

    $server = trafficAnalyticsProxyServer($this, 'NONE', analyticsEnabled: true);
    $server->settings->is_sentinel_enabled = true;
    $server->settings->save();

    expect(ConfigureTrafficAnalytics::run($server, false))->toBeFalse();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
    Queue::assertNotPushed(RestartProxyJob::class);
});

it('keeps the analytics setting and saved proxy configuration unchanged when saving the configuration fails', function (bool $enable) {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->never();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn("services:\n  traefik:\n    command: []\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturnUsing(function (Server $server, string $configuration) {
        $server->proxy->last_saved_settings = 'new-hash';
        $server->proxy->last_saved_proxy_configuration = $configuration;
        $server->save();

        throw new RuntimeException('SSH connection failed.');
    });

    $server = trafficAnalyticsProxyServer($this, 'TRAEFIK', [
        'status' => 'running',
        'last_saved_settings' => 'old-hash',
        'last_saved_proxy_configuration' => 'old-configuration',
    ], analyticsEnabled: ! $enable);

    expect(fn () => ConfigureTrafficAnalytics::run($server, $enable))
        ->toThrow(RuntimeException::class, 'SSH connection failed.');

    $fresh = $server->fresh();
    expect($fresh->isTrafficAnalyticsEnabled())->toBe(! $enable)
        ->and($server->isTrafficAnalyticsEnabled())->toBe(! $enable)
        ->and($fresh->proxy->get('last_saved_settings'))->toBe('old-hash')
        ->and($fresh->proxy->get('last_saved_proxy_configuration'))->toBe('old-configuration');
    Queue::assertNotPushed(RestartProxyJob::class);
})->with(['enabling' => true, 'disabling' => false]);

it('keeps the analytics setting unchanged when the proxy configuration is not a mapping', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->never();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn("services:\n  traefik:\n    command: '--api'\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->never();

    $server = trafficAnalyticsProxyServer($this, 'TRAEFIK', ['status' => 'running']);

    expect(fn () => ConfigureTrafficAnalytics::run($server, true))->toThrow(Exception::class);

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse()
        ->and($server->isTrafficAnalyticsEnabled())->toBeFalse();
    Queue::assertNotPushed(RestartProxyJob::class);
});

it('saves the configuration without starting a proxy the user stopped', function (array $proxy) {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn("services:\n  traefik:\n    command: []\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once()->withArgs(
        fn (Server $server, string $configuration): bool => str_contains($configuration, '--accesslog=true')
    );

    $server = trafficAnalyticsProxyServer($this, 'TRAEFIK', $proxy);

    expect(ConfigureTrafficAnalytics::run($server, true))->toBeFalse();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue()
        ->and((bool) $server->fresh()->proxy->get('force_stop'))->toBe((bool) ($proxy['force_stop'] ?? false));
    Queue::assertNotPushed(RestartProxyJob::class);
})->with([
    'force stopped' => [['status' => 'running', 'force_stop' => true]],
    'exited' => [['status' => 'exited']],
    'stopped' => [['status' => 'stopped']],
]);

it('restarts a running proxy to apply the configuration', function (string $proxyType) {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn($proxyType === 'CADDY'
        ? "services:\n  caddy:\n    volumes: []\n"
        : "services:\n  traefik:\n    command: []\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once();

    $server = trafficAnalyticsProxyServer($this, $proxyType, ['status' => 'running', 'force_stop' => false]);

    expect(ConfigureTrafficAnalytics::run($server, true))->toBeTrue();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
    Queue::assertPushed(RestartProxyJob::class);
})->with(['TRAEFIK', 'CADDY']);

it('regenerates the stored Caddy labels of applications with read-only labels', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle');
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->andReturn("services:\n  caddy:\n    volumes: []\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle');

    $server = trafficAnalyticsProxyServer($this, 'CADDY');
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $createApplication = fn (): Application => Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://example.com',
    ]);
    $readonly = $createApplication();
    $readonly->update(['custom_labels' => base64_encode(implode("\n", generateLabelsApplication($readonly->fresh())))]);
    $editable = $createApplication();
    $editable->settings->update(['is_container_label_readonly_enabled' => false]);
    $editable->update(['custom_labels' => base64_encode('caddy_0=https://custom.example.com')]);
    $logsTraffic = fn (Application $application): bool => str_contains(base64_decode($application->fresh()->custom_labels), 'log.output=file /traffic/access.log');

    expect($logsTraffic($readonly))->toBeFalse();

    ConfigureTrafficAnalytics::run($server, true);

    expect($logsTraffic($readonly))->toBeTrue()
        ->and(base64_decode($editable->fresh()->custom_labels))->toBe('caddy_0=https://custom.example.com');

    ConfigureTrafficAnalytics::run($server->fresh(), false);

    expect($logsTraffic($readonly))->toBeFalse();
});
