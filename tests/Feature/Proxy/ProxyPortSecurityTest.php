<?php

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Process::fake();

    $this->server = Server::factory()->create(['team_id' => Team::factory()]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->server->proxy->type = ProxyTypes::TRAEFIK->value;
    $this->server->proxy->status = 'exited';
    $this->server->save();
    $this->server->refresh();
});

it('rejects a malicious configuration before database or remote writes', function () {
    $originalProxy = $this->server->proxy->toArray();
    $configuration = "services:\n  traefik:\n    ports:\n      - '`id>/tmp/pwned`:443'\n";

    expect(fn () => SaveProxyConfiguration::run($this->server, $configuration))
        ->toThrow(ValidationException::class);

    expect($this->server->fresh()->proxy->toArray())->toBe($originalProxy);
    Process::assertNothingRan();
});

it('does not execute remote commands for a malformed legacy stored port', function () {
    $this->server->proxy->last_saved_proxy_configuration = "services:\n  traefik:\n    image: traefik:v3.7\n    ports:\n      - '$(id):80'\n";
    $this->server->save();

    expect(CheckProxy::run($this->server))->toBeFalse();
    Process::assertNothingRan();
});

it('builds port checks only from normalized integer ports', function () {
    $method = new ReflectionMethod(CheckProxy::class, 'buildPortCheckScript');
    $script = $method->invoke(new CheckProxy, 443, 'coolify-proxy');

    expect($script)
        ->toContain("grep -q '\"443/tcp\"'")
        ->toContain("sport = ':443'")
        ->toContain("nc -z -w1 127.0.0.1 '443'")
        ->not->toContain('$(', '`', ';id')
        ->and(fn () => $method->invoke(new CheckProxy, '80;id', 'coolify-proxy'))
        ->toThrow(TypeError::class);
});
