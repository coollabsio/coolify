<?php

use App\Actions\Docker\GetContainersStatus;
use App\Enums\ApplicationDeploymentStatus;
use App\Events\ServiceChecked;
use App\Jobs\NotifyResourceStoppedJob;
use App\Jobs\PushServerUpdateJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Application\StatusChanged;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.test']);
    Server::flushIdentityMap();
    Cache::flush();
    Event::fake([ServiceChecked::class]);
    Queue::fake([NotifyResourceStoppedJob::class]);
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00', 'UTC'));

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'status_change_email_notifications' => true,
    ]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->server->load('settings');
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function stoppedNotificationDatabase(array $attributes = []): StandalonePostgresql
{
    return StandalonePostgresql::create(array_merge([
        'uuid' => (string) str()->uuid(),
        'name' => 'postgres-'.str()->random(8),
        'postgres_password' => 'secret',
        'status' => 'running:healthy',
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'environment_id' => test()->environment->id,
    ], $attributes));
}

function stoppedNotificationApplication(): Application
{
    return Application::factory()->create([
        'status' => 'exited',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

/**
 * The Sentinel push of a killed database container: the container still exists, but it exited.
 */
function killedDatabasePush(StandalonePostgresql $database): array
{
    return [
        'containers' => [
            [
                'name' => $database->uuid,
                'state' => 'exited',
                'health_status' => null,
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.type' => 'database',
                    'com.docker.compose.service' => $database->uuid,
                ],
            ],
            [
                'name' => 'coolify-proxy',
                'state' => 'running',
                'labels' => ['coolify.managed' => 'true'],
            ],
        ],
    ];
}

it('queues a stopped notification when Sentinel reports a killed database', function () {
    $database = stoppedNotificationDatabase();

    (new PushServerUpdateJob($this->server, killedDatabasePush($database)))->handle();

    expect($database->refresh()->status)->toStartWith('exited');
    Queue::assertPushed(NotifyResourceStoppedJob::class, fn (NotifyResourceStoppedJob $job): bool => $job->resource->is($database));
});

it('queues a stopped notification when the SSH status check finds a killed database', function () {
    $database = stoppedNotificationDatabase();
    $container = [
        'Name' => '/'.$database->uuid,
        'State' => ['Status' => 'exited'],
        'Config' => ['Labels' => [
            'coolify.managed' => 'true',
            'coolify.type' => 'database',
            'com.docker.compose.service' => $database->uuid,
        ]],
    ];

    GetContainersStatus::run($this->server, collect([$container]), collect());

    expect($database->refresh()->status)->toStartWith('exited');
    Queue::assertPushed(NotifyResourceStoppedJob::class, fn (NotifyResourceStoppedJob $job): bool => $job->resource->is($database));
});

it('does not queue a notification for a resource that was already stopped', function () {
    $database = stoppedNotificationDatabase(['status' => 'exited']);

    (new PushServerUpdateJob($this->server, killedDatabasePush($database)))->handle();

    Queue::assertNotPushed(NotifyResourceStoppedJob::class);
});

it('does not queue a notification when Coolify stops the resource', function () {
    $database = stoppedNotificationDatabase();
    NotifyResourceStoppedJob::expectStop($database);

    (new PushServerUpdateJob($this->server, killedDatabasePush($database)))->handle();

    Queue::assertNotPushed(NotifyResourceStoppedJob::class);
});

it('notifies the team once when the resource is still stopped after the delay', function () {
    $database = stoppedNotificationDatabase(['status' => 'exited']);

    (new NotifyResourceStoppedJob($database))->handle();
    (new NotifyResourceStoppedJob($database))->handle();

    Notification::assertSentToTimes($this->team, StatusChanged::class, 1);
    Notification::assertSentTo($this->team, StatusChanged::class, function (StatusChanged $notification) use ($database): bool {
        return $notification->resource->is($database)
            && str($notification->resource_url)->endsWith("/database/{$database->uuid}");
    });

    Carbon::setTestNow(now()->addMinutes(16));
    (new NotifyResourceStoppedJob($database))->handle();

    Notification::assertSentToTimes($this->team, StatusChanged::class, 2);
});

it('does not notify when the resource is running again after the delay', function () {
    $database = stoppedNotificationDatabase(['status' => 'running:healthy']);

    (new NotifyResourceStoppedJob($database))->handle();

    Notification::assertNothingSent();
});

it('does not notify when the restart limit stopped the resource', function () {
    $application = stoppedNotificationApplication();
    $application->forceFill(['restart_limit_reached' => true])->saveQuietly();

    (new NotifyResourceStoppedJob($application))->handle();

    Notification::assertNothingSent();
});

it('does not notify while a deployment replaces the application container', function () {
    $application = stoppedNotificationApplication();
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => (string) $application->id,
        'deployment_uuid' => (string) str()->uuid(),
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    (new NotifyResourceStoppedJob($application))->handle();
    Notification::assertNothingSent();

    $deployment->update(['status' => ApplicationDeploymentStatus::FAILED->value]);
    (new NotifyResourceStoppedJob($application))->handle();
    Notification::assertSentTo($this->team, StatusChanged::class);
});
