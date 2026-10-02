<?php

use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
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

it('does not schedule Stripe subscription reconciliation automatically', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($event) => str_contains((string) $event->description, 'SyncStripeSubscriptions')
    );

    expect($event)->toBeNull();
});

it('schedules stuck resource cleanup in the background once per day', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($event) => str_contains($event->command, 'cleanup:stucked-resources')
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('17 3 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});

it('runs one scheduled job dispatcher for each schedule type from the scheduler instead of a queue', function () {
    $events = collect(app(Schedule::class)->events());

    $dispatchers = $events->filter(fn ($event) => str_contains((string) $event->command, 'scheduled:dispatch'));

    expect($dispatchers->map(fn ($event) => str((string) $event->command)->after('--type=')->value())->values()->all())
        ->toBe(['backups', 'tasks', 'volume-backups', 'docker-cleanups']);
    $dispatchers->each(function ($event) {
        expect($event->expression)->toBe('* * * * *')
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->runInBackground)->toBeTrue();
    });
    // Each type has its own overlap lock.
    expect($dispatchers->map->mutexName()->unique())->toHaveCount(4)
        ->and($events->contains(fn ($event) => str_contains((string) $event->description, 'ScheduledJobManager')))->toBeFalse();
});

it('schedules GitHub runner reconciliation every minute on Coolify Cloud', function () {
    config()->set('constants.coolify.self_hosted', false);

    $schedule = new Schedule;
    $kernel = app(Kernel::class);
    (fn () => $this->schedule($schedule))->call($kernel);

    $event = collect($schedule->events())->first(
        fn ($event) => str_contains((string) $event->description, 'ReconcileGithubRunnersJob')
    );

    expect(isCloud())->toBeTrue()
        ->and($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue();
});
