<?php

use App\Models\PrivateKey;
use App\Models\ScheduledJobState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['constants.coolify.self_hosted' => true]);
});

it('does not consume the dedup keys of the server manager checks', function () {
    // Every check of ServerManagerJob is due at Sunday 00:00, including the weekly patch check.
    Carbon::setTestNow(Carbon::create(2026, 10, 4, 0, 0, 0, 'UTC'));
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);

    $this->artisan('scheduled:diagnostics', ['--type' => 'server-jobs'])->assertSuccessful();

    expect(Cache::get("server-check:{$server->id}"))->toBeNull()
        ->and(Cache::get("server-patch-check:{$server->id}"))->toBeNull()
        ->and(shouldRunCronNow('0 0 * * 0', 'UTC', "server-patch-check:{$server->id}"))->toBeTrue();
});

it('shows the occurrence state that the scheduled job manager records', function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    ScheduledJobState::query()->create([
        'schedule_key' => "docker-cleanup:{$server->id}",
        'last_scheduled_for' => Carbon::create(2026, 10, 2, 0, 0, 0, 'UTC'),
    ]);

    $this->artisan('scheduled:diagnostics', ['--type' => 'docker-cleanup'])
        ->expectsOutputToContain('2026-10-02T00:00:00')
        ->assertSuccessful();
});
