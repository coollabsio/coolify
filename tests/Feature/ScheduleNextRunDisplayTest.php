<?php

use App\Livewire\Project\Database\BackupEdit;
use App\Livewire\Project\Shared\ScheduledTask\Show;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Livewire\Server\DockerCleanup;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 10, 20, 30, 'UTC'));
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['server_timezone' => 'Europe/Budapest']);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

afterEach(function () {
    Event::forget(RouteMatched::class);
    Carbon::setTestNow();
});

function nextRunDisplayBindTaskRoute(Application $application, ScheduledTask $task): void
{
    $application->loadMissing('environment.project');

    Event::listen(RouteMatched::class, function (RouteMatched $event) use ($application, $task): void {
        $event->route->setParameter('task_uuid', $task->uuid);
        $event->route->setParameter('project_uuid', $application->environment->project->uuid);
        $event->route->setParameter('environment_uuid', $application->environment->uuid);
        $event->route->setParameter('application_uuid', $application->uuid);
    });
}

function nextRunDisplaySetCleanupNextRun(Server $server, ?Carbon $nextRunAt): void
{
    ServerSetting::query()->where('server_id', $server->id)->update(['docker_cleanup_next_run_at' => $nextRunAt]);
}

it('shows the next Docker cleanup in the server timezone and refreshes it after saving a new frequency', function () {
    $this->server->settings->update(['docker_cleanup_frequency' => '0 2 * * *']);

    Livewire::test(DockerCleanup::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Next run')
        ->assertSee('2026-09-18 02:00 (Europe/Budapest)', false)
        ->set('dockerCleanupFrequency', 'hourly')
        ->call('submit')
        ->assertSee('2026-09-17 13:00 (Europe/Budapest)', false)
        ->assertSee('in 39 minutes');
});

it('shows Calculating when the Docker cleanup next run is not calculated yet', function () {
    nextRunDisplaySetCleanupNextRun($this->server, null);

    Livewire::test(DockerCleanup::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Next run')
        ->assertSee('Calculating…');
});

it('marks the Docker cleanup as stale only when its next run is more than 10 minutes overdue', function (?int $minutesFromNow, bool $stale) {
    nextRunDisplaySetCleanupNextRun($this->server, $minutesFromNow === null ? null : now()->addMinutes($minutesFromNow));

    $component = Livewire::test(DockerCleanup::class, ['server_uuid' => $this->server->uuid]);

    expect($component->instance()->isCleanupStale())->toBe($stale);
    if ($stale) {
        $component->assertSee('Docker cleanup may be stalled');
    } else {
        $component->assertDontSee('Docker cleanup may be stalled');
    }
})->with([
    '30 minutes overdue' => [-30, true],
    '5 minutes overdue' => [-5, false],
    'in the future' => [30, false],
    'not calculated yet' => [null, false],
]);

it('does not mark the Docker cleanup as stale on a server without a real IP address', function () {
    $this->server->update(['ip' => Server::PLACEHOLDER_IP]);
    nextRunDisplaySetCleanupNextRun($this->server, now()->subMinutes(30));

    $component = Livewire::test(DockerCleanup::class, ['server_uuid' => $this->server->uuid]);

    expect($component->instance()->isCleanupStale())->toBeFalse();
});

it('shows the next scheduled task run in the server timezone and refreshes it on changes', function () {
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
        'frequency' => '0 2 * * *',
        'enabled' => true,
    ]);
    nextRunDisplayBindTaskRoute($this->application, $task);

    $component = Livewire::test(Show::class)
        ->assertSee('Next run')
        ->assertSee('2026-09-18 02:00 (Europe/Budapest)', false)
        ->assertSee('in 13 hours');

    $component->call('toggleEnabled')
        ->assertDontSee('2026-09-18 02:00 (Europe/Budapest)', false)
        ->assertSeeInOrder(['Next run', 'Disabled']);

    $component->call('toggleEnabled')
        ->set('frequency', 'hourly')
        ->call('submit')
        ->assertSee('2026-09-17 13:00 (Europe/Budapest)', false);

    expect($task->fresh()->next_run_at->toDateTimeString())->toBe('2026-09-17 11:00:00');
});

it('shows Calculating for an enabled scheduled task without a next run', function () {
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
        'frequency' => '0 2 * * *',
        'enabled' => true,
    ]);
    ScheduledTask::query()->whereKey($task->id)->update(['next_run_at' => null]);
    nextRunDisplayBindTaskRoute($this->application, $task);

    Livewire::test(Show::class)->assertSee('Calculating…');
});

it('shows an invalid frequency instead of Calculating when the next run cannot be calculated', function () {
    $task = ScheduledTask::factory()->create([
        'team_id' => $this->team->id,
        'application_id' => $this->application->id,
        'frequency' => '0 2 * * *',
        'enabled' => true,
    ]);
    // The forms reject such a value, so only old data or a direct database change can have it.
    ScheduledTask::query()->whereKey($task->id)->update(['frequency' => '60 * * * *', 'next_run_at' => null]);
    nextRunDisplayBindTaskRoute($this->application, $task);

    Livewire::test(Show::class)
        ->assertSee('Invalid frequency')
        ->assertDontSee('Calculating…');
});

it('shows an invalid Docker cleanup frequency', function () {
    ServerSetting::query()->where('server_id', $this->server->id)->update(['docker_cleanup_frequency' => '0 0 31 2 *', 'docker_cleanup_next_run_at' => null]);

    Livewire::test(DockerCleanup::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Invalid frequency')
        ->assertDontSee('Calculating…');
});

it('shows the next database backup run in the server timezone', function () {
    $database = StandalonePostgresql::create([
        'name' => 'pg-next-run',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 2 * * *',
        'enabled' => true,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $this->team->id,
    ]);

    Livewire::test(BackupEdit::class, ['backup' => $backup->fresh(), 'availableS3Storages' => collect()])
        ->assertSee('2026-09-18 02:00 (Europe/Budapest)', false)
        ->call('toggleEnabled')
        ->assertDontSee('2026-09-18 02:00 (Europe/Budapest)', false)
        ->assertSeeInOrder(['Next run', 'Disabled']);
});

it('shows the next volume backup run in the server timezone', function () {
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    Livewire::test(VolumeBackups::class, ['storage' => $volume, 'resource' => $this->application])
        ->assertSeeInOrder(['Next run', 'Disabled'])
        ->set('frequency', '0 2 * * *')
        ->call('toggleEnabled')
        ->assertSee('2026-09-18 02:00 (Europe/Budapest)', false);
});
