<?php

use App\Console\Kernel as ConsoleKernel;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\PullChangelog;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\UpdateCoolifyJob;
use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

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

it('refreshes the local versions.json hourly on every cloud node', function (bool $cloud) {
    config(['constants.coolify.self_hosted' => ! $cloud]);
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($e) => (string) $e->description === 'versions:refresh-local'
    );

    expect($event)->not->toBeNull();
    expect($event->onOneServer)->toBeFalse();
    expect($event->expression)->toBe('0 * * * *');
    expect($event->filtersPass(app()))->toBe($cloud);
})->with(['cloud' => true, 'self-hosted' => false]);

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

it('delays CDN fetching jobs by a stable offset per instance inside the hourly interval', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_auto_update_enabled' => true]);
    config(['app.id' => 'instance-a']);
    Queue::fake();

    $jobs = [CheckForUpdatesJob::class, PullTemplatesFromCDN::class, PullChangelog::class, UpdateCoolifyJob::class];
    $schedule = app(Schedule::class);
    $expectedDelay = ConsoleKernel::cdnDelaySeconds('0 * * * *');

    foreach ($jobs as $job) {
        $event = collect($schedule->events())->first(fn ($e) => $e->description === $job);
        expect($event)->not->toBeNull("[{$job}] is not scheduled");
        $event->run(app());

        Queue::assertPushed($job, fn ($pushed) => $pushed->delay === $expectedDelay);
    }

    expect(collect($schedule->events())->first(fn ($e) => $e->description === CheckHelperImageJob::class))->toBeNull();
});

it('spreads the CDN delay of many instances over the full hour', function () {
    $delays = collect(range(1, 2000))->map(function (int $i) {
        config(['app.id' => bin2hex(random_bytes(16))]);

        return ConsoleKernel::cdnDelaySeconds('0 * * * *');
    });

    expect($delays->min())->toBeGreaterThanOrEqual(0)
        ->and($delays->max())->toBeLessThan(3600);
    // Each 10-minute window of the hour gets about one sixth of the instances.
    $delays->groupBy(fn (int $delay) => intdiv($delay, 600))
        ->each(fn (Collection $window) => expect($window->count())->toBeGreaterThan(250)->toBeLessThan(420));
});

it('keeps the CDN delay inside a cron interval shorter than one hour', function () {
    config(['app.id' => 'instance-b']);

    expect(ConsoleKernel::cdnDelaySeconds('*/15 * * * *'))->toBeLessThan(900)
        ->and(ConsoleKernel::cdnDelaySeconds('0 0 * * *'))->toBeLessThan(3600);
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

function scheduledJobDispatchersForMode(?string $mode, bool $selfHosted = true): Collection
{
    config()->set('constants.coolify.self_hosted', $selfHosted);
    config()->set('constants.coolify.scheduled_jobs_dispatch_mode', $mode);

    $schedule = new Schedule;
    (fn () => $this->schedule($schedule))->call(app(Kernel::class));

    $events = collect($schedule->events());
    expect($events->contains(fn ($event) => str_contains((string) $event->description, 'ScheduledJobManager')))->toBeFalse();

    $dispatchers = $events->filter(fn ($event) => str_contains((string) $event->command, 'scheduled:dispatch'))->values();
    $dispatchers->each(function ($event) {
        expect($event->expression)->toBe('* * * * *')
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->runInBackground)->toBeTrue();
    });

    return $dispatchers;
}

it('runs all scheduled job types in one dispatcher process by default on self-hosted', function () {
    $dispatchers = scheduledJobDispatchersForMode(null);

    expect($dispatchers)->toHaveCount(1)
        ->and((string) $dispatchers->first()->command)->toEndWith('scheduled:dispatch')
        ->and((string) $dispatchers->first()->command)->not->toContain('--type=');
});

it('runs one scheduled job dispatcher for each schedule type in concurrent mode', function (?string $mode, bool $selfHosted) {
    $dispatchers = scheduledJobDispatchersForMode($mode, $selfHosted);

    expect($dispatchers->map(fn ($event) => str((string) $event->command)->after('--type=')->value())->all())
        ->toBe(['backups', 'tasks', 'volume-backups', 'docker-cleanups'])
        // Each type has its own overlap lock.
        ->and($dispatchers->map->mutexName()->unique())->toHaveCount(4);
})->with([
    'set on self-hosted' => ['concurrent', true],
    'default on Coolify Cloud' => [null, false],
]);

it('runs one dispatcher process when sequential mode is set on Coolify Cloud', function () {
    expect(scheduledJobDispatchersForMode('sequential', false))->toHaveCount(1);
});

it('falls back to the default dispatch mode for an unknown value', function () {
    expect(scheduledJobDispatchersForMode('parallel'))->toHaveCount(1)
        ->and(scheduledJobDispatchersForMode('parallel', false))->toHaveCount(4);
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
