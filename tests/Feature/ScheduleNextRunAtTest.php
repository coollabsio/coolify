<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ScheduleNextRunRecalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 10, 20, 30, 'UTC'));
});

it('calculates the next due time of a frequency in the server timezone', function (string $frequency, string $timezone, string $after, bool $includeCurrentMinute, ?string $expected) {
    $next = next_cron_run_at($frequency, $timezone, CarbonImmutable::parse($after, 'UTC'), $includeCurrentMinute);

    expect($next?->toDateTimeString())->toBe($expected);
})->with([
    'alias' => ['daily', 'UTC', '2026-09-17 10:20:30', false, '2026-09-18 00:00:00'],
    'server timezone' => ['0 2 * * *', 'Europe/Budapest', '2026-09-17 10:20:30', false, '2026-09-18 00:00:00'],
    'strictly after' => ['20 10 * * *', 'UTC', '2026-09-17 10:20:00', false, '2026-09-18 10:20:00'],
    'current minute included' => ['20 10 * * *', 'UTC', '2026-09-17 10:20:30', true, '2026-09-17 10:20:00'],
    'a time that does not exist when daylight saving time starts' => ['30 2 * * *', 'Europe/Budapest', '2026-03-28 12:00:00', false, '2026-03-29 01:30:00'],
    'invalid timezone uses the app timezone' => ['0 2 * * *', 'Mars/Olympus', '2026-09-17 10:20:30', false, '2026-09-18 02:00:00'],
    'invalid frequency' => ['not a cron', 'UTC', '2026-09-17 10:20:30', false, null],
]);

it('calculates the next run of a schedule when it is created or its frequency or enabled flag changes', function () {
    $task = nextRunAtTestTask(nextRunAtTestApplication('Europe/Budapest'), '0 2 * * *');

    expect($task->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00');

    $task->update(['frequency' => 'hourly']);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-17 11:00:00');

    $task->update(['enabled' => false]);
    expect($task->fresh()->next_run_at)->toBeNull();

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 30, 0, 'UTC'));
    $task->update(['enabled' => true]);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-17 13:00:00');

    $task->update(['name' => 'renamed']);
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-17 13:00:00');
});

it('calculates the next Docker cleanup when its frequency or the server timezone changes', function () {
    $settings = nextRunAtTestApplication()->destination->server->settings;

    $settings->update(['docker_cleanup_frequency' => '0 2 * * *']);
    expect($settings->fresh()->docker_cleanup_next_run_at->toDateTimeString())->toBe('2026-09-18 02:00:00');

    $settings->update(['server_timezone' => 'Europe/Budapest']);
    expect($settings->fresh()->docker_cleanup_next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00');
});

it('recalculates the next run of the server schedules when the server timezone changes', function () {
    $application = nextRunAtTestApplication();
    $task = nextRunAtTestTask($application, '0 2 * * *');
    $otherTask = nextRunAtTestTask(nextRunAtTestApplication(), '0 2 * * *');

    $application->destination->server->settings->update(['server_timezone' => 'Europe/Budapest']);

    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00')
        ->and($otherTask->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 02:00:00');
});

it('keeps the timezone of the main server for applications that also run on the changed server', function () {
    $application = nextRunAtTestApplication();
    $task = nextRunAtTestTask($application, '0 2 * * *');
    $additionalServer = nextRunAtTestApplication()->destination->server;
    $application->additional_networks()->attach(
        StandaloneDocker::where('server_id', $additionalServer->id)->value('id'),
        ['server_id' => $additionalServer->id],
    );

    $additionalServer->settings->update(['server_timezone' => 'Europe/Budapest']);

    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 02:00:00');
});

it('does not run an occurrence again when the timezone changes in the minute it ran', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 11, 59, 0, 'UTC'));
    $application = nextRunAtTestApplication();
    $task = nextRunAtTestTask($application, '0 * * * *');

    Carbon::setTestNow(Carbon::create(2026, 9, 17, 12, 0, 30, 'UTC'));
    $application->destination->server->settings->update(['server_timezone' => 'Europe/Budapest']);

    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-17 13:00:00');
});

it('recalculates the next run of the resource schedules with the timezone of its current server', function () {
    $application = nextRunAtTestApplication();
    $task = nextRunAtTestTask($application, '0 2 * * *');
    $application->destination->server->settings()->update(['server_timezone' => 'Europe/Budapest']);

    app(ScheduleNextRunRecalculator::class)->forResource($application);

    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-18 00:00:00');
});

it('lists overdue schedules and schedules without a next run in the diagnostics', function () {
    $overdue = nextRunAtTestTask(nextRunAtTestApplication(), '0 2 * * *');
    ScheduledTask::query()->whereKey($overdue->id)->update(['next_run_at' => now()->subHour()]);
    $invalid = nextRunAtTestTask(nextRunAtTestApplication(), '0 2 * * *');
    ScheduledTask::query()->whereKey($invalid->id)->update(['frequency' => '60 * * * *', 'next_run_at' => null]);
    nextRunAtTestTask(nextRunAtTestApplication(), '0 3 * * *');

    $this->artisan('scheduled:diagnostics')
        ->expectsOutputToContain('Scheduled tasks: 3 enabled, 2 overdue or without a next run')
        ->expectsOutputToContain('invalid frequency')
        ->assertSuccessful();
});

it('continues each schedule after the occurrence that the previous dispatcher recorded last', function () {
    $task = nextRunAtTestTask(nextRunAtTestApplication(), '0 2 * * *');
    Schema::create('scheduled_job_states', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('schedule_key')->unique();
        $table->timestampTz('last_scheduled_for')->nullable();
        $table->timestamps();
    });
    DB::table('scheduled_job_states')->insert([
        'uuid' => 'state-1',
        'schedule_key' => "scheduled-task:{$task->id}",
        'last_scheduled_for' => '2026-09-16 02:00:00',
    ]);

    $migration = require database_path('migrations/2026_09_28_194652_add_next_run_at_to_scheduled_jobs.php');
    $migration->continueAfterLastScheduledOccurrences();

    // The occurrence of 2026-09-17 02:00 was not recorded, so the dispatcher runs it on its next run.
    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-17 02:00:00');
});

function nextRunAtTestApplication(string $timezone = 'UTC'): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['server_timezone' => $timezone]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $team->id])->id,
    ]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

function nextRunAtTestTask(Application $application, string $frequency): ScheduledTask
{
    return ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => $frequency,
        'enabled' => true,
    ])->fresh();
}
