<?php

use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

it('schedules RegenerateSslCertJob with onOneServer to prevent multi-server double dispatch', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($e) => str_contains((string) $e->description, 'RegenerateSslCertJob')
    );

    expect($event)->not->toBeNull();
    expect($event->onOneServer)->toBeTrue();
});

it('schedules ssh mux cleanup locally on every scheduler host', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($e) => (string) $e->description === 'cleanup:ssh-mux'
    );

    expect($event)->not->toBeNull();
    expect($event->onOneServer)->toBeFalse();
    expect($event->getSummaryForDisplay())->toBe('cleanup:ssh-mux');
});

it('schedules every production job with onOneServer', function () {
    $schedule = app(Schedule::class);

    $jobEvents = collect($schedule->events())->filter(
        fn ($e) => str_contains((string) $e->description, 'App\\Jobs\\')
    );

    expect($jobEvents)->not->toBeEmpty();

    $jobEvents->each(function ($event) {
        expect($event->onOneServer)->toBeTrue(
            "Scheduled job [{$event->description}] is missing ->onOneServer()"
        );
    });
});
