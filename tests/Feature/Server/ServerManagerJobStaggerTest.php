<?php

use App\Enums\ServerRole;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerManagerJob;
use App\Jobs\ServerPatchCheckJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow('2025-01-15 12:00:00');
    InstanceSettings::forceCreate(['id' => 0, 'instance_timezone' => 'UTC']);
    $this->team = Team::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function createStaggerTestServer(Team $team): Server
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => Carbon::now(),
    ]);
    $server->settings->update([
        'server_timezone' => 'UTC',
        'server_role' => ServerRole::BOTH,
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    return $server->refresh();
}

function runStaggerTestManagerAt(Carbon $time): void
{
    Carbon::setTestNow($time);
    Server::query()->update(['sentinel_updated_at' => $time]);
    Server::flushIdentityMap();
    (new ServerManagerJob)->handle();
}

it('gives each server a stable slot and spreads servers across minutes', function () {
    $servers = collect(range(1, 120))->map(fn (int $id) => (new Server)->forceFill(['id' => $id]));

    $hourlyMinutes = $servers->map(fn (Server $server) => ServerManagerJob::sentinelVersionCheckCron($server));
    $weeklySlots = $servers->map(fn (Server $server) => ServerManagerJob::patchCheckCron($server));

    expect($hourlyMinutes->unique())->toHaveCount(60)
        ->and($weeklySlots->unique())->toHaveCount(120)
        ->and(ServerManagerJob::sentinelVersionCheckCron($servers[6]))->toBe(ServerManagerJob::sentinelVersionCheckCron($servers[6]))
        ->and(ServerManagerJob::patchCheckCron($servers[6]))->toBe(ServerManagerJob::patchCheckCron($servers[6]));

    $weeklySlots->each(function (string $cron) {
        [$minute, $hour, , , $dayOfWeek] = explode(' ', $cron);
        expect((int) $minute)->toBeBetween(0, 59)
            ->and((int) $hour)->toBeBetween(4, 23)
            ->and($dayOfWeek)->toBe('0');
    });
});

it('dispatches the Sentinel version check once per hour at the server minute', function () {
    $server = createStaggerTestServer($this->team);
    $minute = $server->id % 60;
    $slot = Carbon::parse('2025-01-15 12:00:00')->setMinute($minute);

    runStaggerTestManagerAt($slot->copy()->addMinute());
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);

    runStaggerTestManagerAt($slot->copy()->addHour());
    runStaggerTestManagerAt($slot->copy()->addHour()->addSeconds(30));
    runStaggerTestManagerAt($slot->copy()->addHour()->addMinute());
    Queue::assertPushed(CheckAndStartSentinelJob::class, 1);

    runStaggerTestManagerAt($slot->copy()->addHours(2));
    Queue::assertPushed(CheckAndStartSentinelJob::class, 2);
});

it('catches up a Sentinel version check when the server minute was not evaluated', function () {
    $server = createStaggerTestServer($this->team);
    $slot = Carbon::parse('2025-01-15 12:00:00')->setMinute($server->id % 60);

    runStaggerTestManagerAt($slot->copy()->subMinute());
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);

    runStaggerTestManagerAt($slot->copy()->addMinutes(3));
    Queue::assertPushed(CheckAndStartSentinelJob::class, 1);
});

it('dispatches the patch check once per week at the server slot on Sunday', function () {
    $server = createStaggerTestServer($this->team);
    [$minute, $hour] = array_map('intval', explode(' ', ServerManagerJob::patchCheckCron($server)));
    $sunday = Carbon::parse('2025-01-19 00:00:00');
    $slot = $sunday->copy()->setTime($hour, $minute);

    runStaggerTestManagerAt($sunday);
    Queue::assertNotPushed(ServerPatchCheckJob::class);

    runStaggerTestManagerAt($slot);
    runStaggerTestManagerAt($slot->copy()->addMinute());
    Queue::assertPushed(ServerPatchCheckJob::class, 1);

    runStaggerTestManagerAt($slot->copy()->addWeek());
    Queue::assertPushed(ServerPatchCheckJob::class, 2);
});
