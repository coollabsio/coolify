<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

class TestableStopGracePeriodDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}

    public function execute_remote_command(...$commands): void {}
}

function generateComposeServiceWithStopGracePeriod(?int $stopGracePeriod): array
{
    $team = Team::create([
        'name' => 'Stop Grace Period Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $project = Project::create([
        'name' => 'Stop Grace Period Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
    ]);
    $application->settings()->update(['stop_grace_period' => $stopGracePeriod]);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->status = 'queued';
    $queue->shouldReceive('refresh')->once()->andReturnSelf();

    $job = new TestableStopGracePeriodDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'destination' => $destination,
        'server' => $server,
        'mainServer' => $server,
        'pull_request_id' => 0,
        'container_name' => 'stop-grace-period-app',
        'production_image_name' => 'example/app:latest',
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/stop-grace-period-app',
        'configuration_dir' => '/data/coolify/applications/test',
        'saved_outputs' => new Collection,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $reflection->getMethod('generate_compose_file')->invoke($job);
    $compose = Yaml::parse($reflection->getProperty('docker_compose')->getValue($job));

    return $compose['services']['stop-grace-period-app'];
}

it('adds an explicitly configured stop grace period to generated compose services', function () {
    expect(generateComposeServiceWithStopGracePeriod(700))
        ->toHaveKey('stop_grace_period', '700s');
});

it('omits the stop grace period from generated compose services when it is not configured', function () {
    expect(generateComposeServiceWithStopGracePeriod(null))
        ->not->toHaveKey('stop_grace_period');
});
