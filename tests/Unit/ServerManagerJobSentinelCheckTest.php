<?php

use App\Enums\ServerRole;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2025-01-15 12:00:00');
    InstanceSettings::forceCreate(['id' => 0, 'instance_timezone' => 'UTC']);
    $this->team = Team::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function createSentinelCheckServer(Team $team, Carbon $sentinelUpdatedAt, ServerRole $role = ServerRole::BOTH): Server
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => $sentinelUpdatedAt,
    ]);
    $server->settings->update([
        'server_timezone' => 'UTC',
        'server_role' => $role,
        'is_build_server' => $role === ServerRole::BUILD,
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    return $server->refresh();
}

it('dispatches an hourly Sentinel version check for a healthy Sentinel', function () {
    $server = createSentinelCheckServer($this->team, Carbon::now());

    expect($server->isSentinelEnabled())->toBeTrue()
        ->and($server->isSentinelLive())->toBeTrue();

    $job = new ServerManagerJob;
    $job->handle();

    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('does not schedule periodic Sentinel restart checks', function () {
    $root = dirname(__DIR__, 2);
    $manager = file_get_contents($root.'/app/Jobs/ServerManagerJob.php');
    $diagnostics = file_get_contents($root.'/app/Console/Commands/ScheduledJobDiagnostics.php');

    expect($manager)->not->toContain('sentinel-restart:')
        ->and($diagnostics)->not->toContain('sentinel-restart:');
});

it('skips ServerConnectionCheckJob when sentinel is live', function () {
    $server = createSentinelCheckServer($this->team, Carbon::now());

    expect($server->isSentinelEnabled())->toBeTrue()
        ->and($server->isSentinelLive())->toBeTrue();

    $job = new ServerManagerJob;
    $job->handle();

    // Sentinel is healthy so SSH connection check is skipped
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('dispatches ServerConnectionCheckJob when sentinel is live but the server is marked unusable', function (string $flag) {
    // A Sentinel heartbeat does not restore these flags, so only the SSH check can recover them
    $server = createSentinelCheckServer($this->team, Carbon::now());
    $server->settings->update([$flag => false]);

    expect($server->isSentinelLive())->toBeTrue();

    $job = new ServerManagerJob;
    $job->handle();

    Queue::assertPushed(ServerConnectionCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
})->with(['is_reachable', 'is_usable']);

it('dispatches ServerConnectionCheckJob when sentinel is not live', function () {
    $server = createSentinelCheckServer($this->team, Carbon::now()->subMinutes(10));

    expect($server->isSentinelEnabled())->toBeTrue()
        ->and($server->isSentinelLive())->toBeFalse();

    $job = new ServerManagerJob;
    $job->handle();

    // Sentinel is out of sync so SSH connection check is needed
    Queue::assertPushed(ServerConnectionCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('dispatches ServerConnectionCheckJob when sentinel is not enabled', function () {
    // Build servers cannot run Sentinel, so a fresh heartbeat timestamp must not skip the SSH check
    $server = createSentinelCheckServer($this->team, Carbon::now(), ServerRole::BUILD);

    expect($server->isSentinelEnabled())->toBeFalse();

    $job = new ServerManagerJob;
    $job->handle();

    // Sentinel is not enabled so SSH connection check must run
    Queue::assertPushed(ServerConnectionCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});
