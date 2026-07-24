<?php

use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ServiceChecked;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Application\RestartLimitReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

function makeComposeApplication(): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);

    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $compose = <<<'YAML'
    services:
      web:
        image: nginx
        restart: unless-stopped
      cron:
        image: busybox
        restart: no
      ofelia:
        image: mcuadros/ofelia:latest
        restart: unless-stopped
        exclude_from_hc: true
    YAML;

    return Application::factory()->create([
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'status' => 'running:healthy',
        'restart_count' => 0,
        'max_restart_count' => 10,
    ]);
}

function inspectContainer(int $applicationId, string $service, string $state, ?string $health, int $restartCount): array
{
    $stateData = ['Status' => $state];
    if ($health !== null) {
        $stateData['Health'] = ['Status' => $health];
    }

    return [
        'Config' => [
            'Labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationId' => (string) $applicationId,
                'com.docker.compose.service' => $service,
            ],
        ],
        'State' => $stateData,
        'RestartCount' => $restartCount,
    ];
}

test('excluded containers do not trip the restart limit', function () {
    Queue::fake();
    Notification::fake();
    Event::fake([ServiceChecked::class]);

    $application = makeComposeApplication();
    $server = $application->destination->server;
    $team = $application->environment->project->team;

    // Excluded cron (restart:no) and ofelia (exclude_from_hc) have restarted 10x; web is healthy.
    GetContainersStatus::run($server, collect([
        inspectContainer($application->id, 'web', 'running', 'healthy', 0),
        inspectContainer($application->id, 'cron', 'exited', null, 10),
        inspectContainer($application->id, 'ofelia', 'running', 'healthy', 10),
    ]));

    $application->refresh();

    expect($application->restart_count)->toBe(0)
        ->and($application->status)->toBe('running:healthy');

    Notification::assertNotSentTo($team, RestartLimitReached::class);
});

test('a real crash loop on a monitored container still trips the restart limit', function () {
    Queue::fake();
    Notification::fake();
    Event::fake([ServiceChecked::class]);

    $application = makeComposeApplication();
    $server = $application->destination->server;

    // The monitored web container itself has restarted up to the limit.
    GetContainersStatus::run($server, collect([
        inspectContainer($application->id, 'web', 'running', 'healthy', 10),
        inspectContainer($application->id, 'cron', 'exited', null, 0),
    ]));

    $application->refresh();

    // Non-excluded restarts ARE counted, reaching the limit as a crash.
    expect($application->restart_count)->toBe(10)
        ->and($application->last_restart_type)->toBe('crash');

    // Which is exactly when the app is stopped (AsAction queues via JobDecorator).
    Queue::assertPushed(JobDecorator::class, fn (JobDecorator $job) => $job->getAction() instanceof StopApplication);
});
