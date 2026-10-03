<?php

use App\Actions\Server\StartSentinel;
use App\Jobs\CheckAndStartSentinelJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    Process::fake([
        '*docker ps*' => Process::result(output: 'running'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server))->handle();
});

it('uses sudo for the status check on a non-root server', function () {
    $this->server->update(['user' => 'ubuntu']);
    Process::fake([
        '*docker ps*' => Process::result(output: 'running'),
        '*api/version*' => Process::result(output: '1.0.0'),
    ]);
    StartSentinel::shouldNotRun();

    (new CheckAndStartSentinelJob($this->server->refresh()))->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'sudo docker ps -a'));
});
