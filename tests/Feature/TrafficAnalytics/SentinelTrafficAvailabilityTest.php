<?php

use App\Livewire\Analytics;
use App\Livewire\Dashboard\TrafficAnalytics;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A dead server must not stall analytics pages that poll every Sentinel over SSH.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Cache::flush();
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.retry_base_delay' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_traffic_analytics_enabled' => true]);
});

function markTrafficServerReachable(Server $server): void
{
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $server->refresh();
}

it('does not run a remote command for a server that is not reachable', function () {
    Process::fake(fn () => Process::result(output: '{"requests":1}'));
    $this->server->settings->update(['is_reachable' => false]);
    $client = new SentinelTrafficClient($this->server->fresh());
    [$from, $to] = SentinelTrafficClient::rangeWindow('24h');

    expect(fn () => $client->overview(null, $from, $to))->toThrow(RuntimeException::class);
    expect($client->prefetchServerWide(null, $from, $to, [], '24h'))->toBe([]);

    Process::assertNothingRan();
});

it('skips the dashboard fetch of an unreachable server without a remote call', function () {
    Process::fake(fn () => Process::result(output: '{"requests":1}'));
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    loadLazy(Livewire::test(TrafficAnalytics::class))
        ->assertOk()
        ->assertSet('overview', null);

    Process::assertNothingRan();
});

it('tries a failing server once and skips it until the failure window ends', function () {
    markTrafficServerReachable($this->server);
    Process::fake(fn () => Process::result(
        errorOutput: "ssh: connect to host {$this->server->ip} port 22: Connection timed out",
        exitCode: 255,
    ));
    $client = new SentinelTrafficClient($this->server);

    expect(fn () => $client->overview(null, 'a', 'b'))->toThrow(RuntimeException::class);
    Process::assertRanTimes(fn () => true, 1);

    expect(fn () => $client->overview('app', 'a', 'b'))->toThrow(RuntimeException::class);
    Process::assertRanTimes(fn () => true, 1);

    $this->travel(SentinelTrafficClient::UNAVAILABLE_TTL + 1)->seconds();

    expect(fn () => $client->overview('app', 'a', 'b'))->toThrow(RuntimeException::class);
    Process::assertRanTimes(fn () => true, 2);
});

it('fetches a healthy server with a bounded ssh and curl timeout', function () {
    markTrafficServerReachable($this->server);
    Process::fake(fn () => Process::result(output: '{"requests":7}'));

    $overview = (new SentinelTrafficClient($this->server))->overview(null, 'a', 'b');

    expect($overview->requests)->toBe(7);
    Process::assertRan(fn (PendingProcess $process) => $process->timeout <= 30
        && str_contains($process->command, '--max-time'));
});

it('shows no data without an error when Sentinel has no traffic routes (0.0.x answers 404)', function () {
    markTrafficServerReachable($this->server);
    // Gin's 404 body for every unknown route, including /api/traffic/dashboard.
    Process::fake(fn () => Process::result(output: '404 page not found'));
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSet('overview', null)
        ->assertSet('topApps', [])
        ->assertSet('series', [])
        ->assertNotDispatched('error');

    loadLazy(Livewire::test(TrafficAnalytics::class))
        ->assertOk()
        ->assertSet('overview', null)
        ->assertSet('series', [])
        ->assertNotDispatched('error');

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, '/api/traffic/dashboard'));
});
