<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Process::fake();

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->server->proxy->type = ProxyTypes::TRAEFIK->value;
    $this->server->save();
    $this->server->refresh();
});

it('rejects a renamed proxy container before database or remote writes', function () {
    $originalProxy = $this->server->proxy->toArray();
    $configuration = "services:\n  traefik:\n    container_name: utility-servers-proxy\n    image: traefik:v3.7\n";

    expect(fn () => SaveProxyConfiguration::run($this->server, $configuration))
        ->toThrow(ValidationException::class, 'The traefik container_name must be coolify-proxy.');

    expect($this->server->fresh()->proxy->toArray())->toBe($originalProxy);
    Process::assertNothingRan();
});

it('rejects a proxy service without a container name', function () {
    $configuration = "services:\n  traefik:\n    image: traefik:v3.7\n";

    expect(fn () => SaveProxyConfiguration::run($this->server, $configuration))
        ->toThrow(ValidationException::class, 'The traefik container_name must be coolify-proxy.');

    Process::assertNothingRan();
});

it('saves a proxy configuration with the default container name', function () {
    $configuration = "services:\n  traefik:\n    container_name: coolify-proxy\n    image: traefik:v3.7\n  traefik-logrotate:\n    container_name: coolify-proxy-logrotate\n";

    SaveProxyConfiguration::run($this->server, $configuration);

    expect($this->server->fresh()->proxy->last_saved_proxy_configuration)->toBe($configuration);
});

it('allows any container name on a swarm server', function () {
    $this->server->settings()->update(['is_swarm_manager' => true]);
    $configuration = "services:\n  traefik:\n    container_name: utility-servers-proxy\n    image: traefik:v3.7\n";

    SaveProxyConfiguration::run($this->server->fresh(), $configuration);

    expect($this->server->fresh()->proxy->last_saved_proxy_configuration)->toBe($configuration);
});
