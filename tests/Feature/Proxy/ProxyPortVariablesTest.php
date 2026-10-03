<?php

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Actions\Server\StartSentinel;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\RestartProxyJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Docker Compose interpolates variables in proxy ports, so Coolify must never refuse to save,
 * start, or restart a proxy because a port uses a variable (like v4.3.23 did not).
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([ProxyStatusChanged::class, ProxyStatusChangedUI::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Process::fake();

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => 'cooluser',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->server->proxy->type = ProxyTypes::TRAEFIK->value;
    $this->server->proxy->status = 'exited';
    $this->server->save();
    $this->server->refresh();
});

dataset('proxy port variables', [
    'host port default' => ['${HTTP_PORT:-80}:80'],
    'host port without default' => ['${HTTP_PORT}:80'],
    'host IP with a host port default' => ['127.0.0.1:${P:-8080}:8080'],
    'host IP default' => ['${BIND_IP:-0.0.0.0}:80:80'],
]);

function proxyConfigurationWithPort(string $port): string
{
    return "services:\n  traefik:\n    image: traefik:v3.6\n    ports:\n      - '{$port}'\n      - '443:443'\n";
}

it('saves a proxy configuration whose ports use variables', function (string $port) {
    $configuration = proxyConfigurationWithPort($port);

    SaveProxyConfiguration::run($this->server, $configuration);

    expect($this->server->fresh()->proxy->last_saved_proxy_configuration)->toBe($configuration);
})->with('proxy port variables');

it('starts a proxy whose ports use variables', function (string $port) {
    $this->server->proxy->last_saved_proxy_configuration = proxyConfigurationWithPort($port);
    $this->server->save();

    StartProxy::run($this->server, async: false, force: true);

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker compose -f /data/coolify/proxy/docker-compose.yml up -d --wait --remove-orphans'));
})->with('proxy port variables');

it('restarts a proxy whose ports use variables', function (string $port) {
    Queue::fake();
    $this->server->proxy->last_saved_proxy_configuration = proxyConfigurationWithPort($port);
    $this->server->save();

    $job = new RestartProxyJob($this->server);
    $job->handle();

    expect($job->activity_id)->not->toBeNull()
        ->and($this->server->fresh()->proxy->status)->toBe('restarting');
})->with('proxy port variables');

it('asks to start a stopped proxy whose ports use variables and checks only concrete ports', function () {
    $this->server->proxy->last_saved_proxy_configuration = "services:\n  traefik:\n    image: traefik:v3.6\n    ports:\n      - '\${HTTP_PORT:-8081}:80'\n      - '\${HTTPS_PORT}:443'\n";
    $this->server->save();

    expect(CheckProxy::run($this->server))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, "sport = ':8081'"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'sport = ') && str_contains($process->command, '${'));
});

it('configures traffic analytics for a proxy whose ports use variables', function () {
    Queue::fake();
    StartSentinel::partialMock()->shouldReceive('handle')->atLeast()->once();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn(proxyConfigurationWithPort('${HTTP_PORT}:80'));
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once();
    $this->server->proxy->status = 'running';
    $this->server->save();

    ConfigureTrafficAnalytics::run($this->server, true);

    expect($this->server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
    Queue::assertPushed(RestartProxyJob::class);
});
