<?php

use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Jobs\PushServerUpdateJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Application\RestartLimitReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.test']);

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'restart_limit_reached_email_notifications' => true,
    ]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'restarting',
    ]);
    $this->application->forceFill(['max_restart_count' => 3, 'container_present' => true])->save();
});

/**
 * Reports one container of the application with the given state and Docker restart count.
 */
function reportApplicationContainer(string $path, Application $application, Server $server, string $state, int $restartCount): void
{
    $labels = [
        'coolify.managed' => 'true',
        'coolify.applicationUuid' => $application->uuid,
        'coolify.pullRequestId' => '0',
        'com.docker.compose.project' => $application->uuid,
        'com.docker.compose.service' => $application->uuid,
    ];

    if ($path === 'sentinel') {
        (new PushServerUpdateJob($server, ['containers' => [[
            'name' => $application->uuid,
            'state' => $state,
            'health_status' => 'unhealthy',
            'restart_count' => $restartCount,
            'labels' => $labels,
        ]]]))->handle();

        return;
    }

    GetContainersStatus::run($server->fresh(), collect([[
        'Name' => '/'.$application->uuid,
        'State' => ['Status' => $state],
        'RestartCount' => $restartCount,
        'Config' => ['Labels' => $labels],
    ]]), collect());
}

it('stops the application and notifies once when the restart limit is reached', function (string $path) {
    reportApplicationContainer($path, $this->application, $this->server, 'restarting', 3);

    expect($this->application->fresh()->restart_limit_reached)->toBeTrue();
    StopApplication::assertPushed(1);
    Notification::assertSentToTimes($this->team, RestartLimitReached::class, 1);
})->with(['sentinel', 'status check']);

it('stops the application again when it still restarts after the restart limit was reached', function (string $path) {
    $this->application->forceFill(['restart_count' => 3, 'restart_limit_reached' => true])->save();

    reportApplicationContainer($path, $this->application, $this->server, 'restarting', 5);

    StopApplication::assertPushed(1);
    Notification::assertNothingSent();
})->with(['sentinel', 'status check']);

it('does not stop the application again after its containers exited at the restart limit', function (string $path) {
    $this->application->forceFill(['status' => 'exited', 'restart_count' => 3, 'restart_limit_reached' => true])->save();

    reportApplicationContainer($path, $this->application, $this->server, 'exited', 3);

    StopApplication::assertNotPushed();
    Notification::assertNothingSent();
    expect($this->application->fresh()->stoppedAfterRestartLimit())->toBeTrue();
})->with(['sentinel', 'status check']);
