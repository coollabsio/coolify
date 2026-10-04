<?php

use App\Actions\Server\StartSentinel;
use App\Jobs\CheckAndStartSentinelJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config()->set('constants.ssh.max_retries', 1);
    config()->set('constants.ssh.mux_enabled', false);
    Http::fake(['*' => Http::response(['coolify' => ['sentinel' => ['version' => '1.0.0']]])]);

    $user = User::factory()->create();
    $team = $user->teams()->first();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update([
        'is_sentinel_enabled' => true,
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $this->server->refresh();
});

it('does not connect to an unreachable or unusable server', function (string $flag) {
    $this->server->settings->update([$flag => false]);
    Process::fake();
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server->refresh()))->handle();

    Process::assertNothingRan();
})->with(['is_reachable', 'is_usable']);

it('starts Sentinel when the container is missing', function () {
    Process::fake(['*' => Process::result(output: '')]);
    StartSentinel::shouldRun()->once();

    (new CheckAndStartSentinelJob($this->server))->handle();
});

it('does not treat an SSH failure as a missing container', function () {
    Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host 1.2.3.4 port 22: Connection timed out', exitCode: 255)]);
    StartSentinel::shouldNotRun();

    expect(fn () => (new CheckAndStartSentinelJob($this->server))->handle())
        ->toThrow(RuntimeException::class, 'Connection timed out');
});

it('does not restart a running Sentinel that is up to date', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()])->save();
    Process::fake([
        '*docker ps*' => Process::result(output: 'running'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server))->handle();
});

it('uses sudo for the status check on a non-root server', function () {
    $this->server->forceFill(['user' => 'ubuntu', 'sentinel_updated_at' => now()])->save();
    Process::fake([
        '*docker ps*' => Process::result(output: 'running'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server->refresh()))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'sudo docker ps -a'));
});

it('restarts an unhealthy Sentinel and remembers why', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()])->save();
    Process::fake(['*docker ps*' => Process::result(output: 'running Up 2 hours (unhealthy)')]);
    StartSentinel::shouldRun()->once();

    (new CheckAndStartSentinelJob($this->server))->handle();

    expect($this->server->sentinelPushProblem())->toContain('unhealthy');
});

it('does not restart a Sentinel whose health check is still starting', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()])->save();
    Process::fake([
        '*docker ps*' => Process::result(output: 'running Up 30 seconds (health: starting)'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server))->handle();
});

it('restarts a running Sentinel that stopped pushing, at most once per hour', function () {
    Cache::flush();
    $this->server->forceFill(['sentinel_updated_at' => now()->subMinutes(15), 'sentinel_waiting_since' => null])->save();
    Process::fake([
        '*docker ps*' => Process::result(output: 'running Up 2 hours (healthy)'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);
    StartSentinel::shouldRun()->once();

    (new CheckAndStartSentinelJob($this->server))->handle();
    (new CheckAndStartSentinelJob($this->server))->handle();
});

it('does not restart a Sentinel that started recently and has not pushed yet', function () {
    Cache::flush();
    $this->server->forceFill(['sentinel_updated_at' => now()->subDays(5), 'sentinel_waiting_since' => now()->subMinutes(3)])->save();
    Process::fake([
        '*docker ps*' => Process::result(output: 'running Up 3 minutes (healthy)'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server))->handle();
});

it('remembers the push error that Sentinel reports', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()])->save();
    $pushStatus = json_encode([
        'last_attempt_at' => '2026-10-04T12:00:00Z',
        'last_success_at' => null,
        'last_error' => 'http: error sending request: Connection refused',
        'last_status' => null,
        'consecutive_failures' => 4,
    ]);
    Process::fake([
        '*docker ps*' => Process::result(output: 'running Up 2 hours (healthy)'),
        '*api/version*' => Process::result(output: "1.0.0\n{$pushStatus}"),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server))->handle();

    expect($this->server->sentinelPushProblem())->toBe('Sentinel cannot push to Coolify: http: error sending request: Connection refused');
});

it('reads the push status with the token from inside the Sentinel container on a non-root server', function () {
    $this->server->forceFill(['user' => 'ubuntu', 'sentinel_updated_at' => now()])->save();
    Process::fake([
        '*docker ps*' => Process::result(output: 'running Up 2 hours (healthy)'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);

    (new CheckAndStartSentinelJob($this->server->refresh()))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, "sudo bash -c 'docker exec coolify-sentinel sh -c '\\''curl")
        && str_contains($process->command, 'Bearer $TOKEN'));
});

it('does not restart a starting Sentinel whose API does not answer yet', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()->subDays(5), 'sentinel_waiting_since' => now()])->save();
    Process::fake([
        '*docker ps*' => Process::result(output: 'running Up 3 seconds (health: starting)'),
        '*api/version*' => Process::result(output: ''),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server))->handle();
});
