<?php

use App\Actions\Proxy\StopProxy;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * Traffic analytics adds the `coolify-proxy-logrotate` sidecar to the Traefik compose project. A Caddy
 * project (another compose project) cannot remove it with --remove-orphans, so stop and switch must.
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
    $this->server->proxy->status = 'running';
    $this->server->save();
    $this->server->refresh();
});

/**
 * @return array<int, string>
 */
function logrotateCleanupScriptLines(): array
{
    $lines = [];
    Process::assertRan(function ($process) use (&$lines) {
        array_push($lines, ...explode("\n", $process->command));

        return true;
    });

    return $lines;
}

it('removes the logrotate sidecar when the proxy is stopped', function (string $user, string $expected) {
    $this->server->update(['user' => $user]);

    StopProxy::run($this->server);

    expect(logrotateCleanupScriptLines())->toContain($expected);
})->with([
    'non-root' => ['cooluser', 'sudo docker rm -f coolify-proxy-logrotate 2>/dev/null || sudo true'],
    'root' => ['root', 'docker rm -f coolify-proxy-logrotate 2>/dev/null || true'],
]);

it('removes the logrotate sidecar before Caddy starts after a switch from Traefik', function () {
    $this->server->proxy->status = 'exited';
    $this->server->save();

    $this->server->changeProxy(ProxyTypes::CADDY->value, async: false);

    $lines = logrotateCleanupScriptLines();
    $removal = array_search('sudo docker rm -f coolify-proxy-logrotate 2>/dev/null || sudo true', $lines, true);
    $start = array_search('sudo docker compose -f /data/coolify/proxy/caddy/docker-compose.yml up -d --wait --remove-orphans', $lines, true);

    expect($removal)->toBeInt()
        ->and($start)->toBeInt()
        ->and($removal)->toBeLessThan($start);
});
