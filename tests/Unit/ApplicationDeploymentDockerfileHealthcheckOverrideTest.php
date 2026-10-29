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

class TestableDockerfileHealthcheckDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}

    public function execute_remote_command(...$commands): void {}
}

function generateComposeServiceWithDockerfileHealthcheck(array $applicationAttributes): array
{
    $team = Team::create([
        'name' => 'Dockerfile Healthcheck Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $project = Project::create([
        'name' => 'Dockerfile Healthcheck Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile',
        'ports_exposes' => '4181',
        'custom_healthcheck_found' => true,
        ...$applicationAttributes,
    ]);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->status = 'queued';
    $queue->shouldReceive('refresh')->once()->andReturnSelf();
    $queue->shouldReceive('addLogEntry');

    $job = new TestableDockerfileHealthcheckDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'destination' => $destination,
        'server' => $server,
        'mainServer' => $server,
        'pull_request_id' => 0,
        'container_name' => 'dockerfile-healthcheck-app',
        'production_image_name' => 'example/app:latest',
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/dockerfile-healthcheck-app',
        'configuration_dir' => '/data/coolify/applications/test',
        'dockerfile_location' => '/Dockerfile',
        'saved_outputs' => new Collection([
            'dockerfile_from_repo' => "FROM scratch\nHEALTHCHECK --interval=30s --timeout=3s CMD [\"/app\", \"healthcheck\"]\n",
        ]),
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $reflection->getMethod('generate_compose_file')->invoke($job);
    $compose = Yaml::parse($reflection->getProperty('docker_compose')->getValue($job));

    return $compose['services']['dockerfile-healthcheck-app'];
}

it('replaces the Dockerfile HEALTHCHECK with the enabled HTTP healthcheck', function () {
    $service = generateComposeServiceWithDockerfileHealthcheck([
        'health_check_enabled' => true,
        'health_check_type' => 'http',
        'health_check_port' => '4181',
        'health_check_path' => '/health',
        'health_check_interval' => 300,
        'health_check_timeout' => 3,
    ]);

    expect($service['healthcheck']['interval'])->toBe('300s')
        ->and($service['healthcheck']['timeout'])->toBe('3s')
        ->and($service['healthcheck']['test'][1])->toContain("'http://localhost:4181/health'");
});

it('replaces the Dockerfile HEALTHCHECK with the enabled command healthcheck', function () {
    $service = generateComposeServiceWithDockerfileHealthcheck([
        'health_check_enabled' => true,
        'health_check_type' => 'cmd',
        'health_check_command' => '/app healthcheck',
        'health_check_interval' => 300,
    ]);

    expect($service['healthcheck']['test'])->toBe(['CMD-SHELL', '/app healthcheck'])
        ->and($service['healthcheck']['interval'])->toBe('300s');
});

it('keeps the Dockerfile HEALTHCHECK when the Coolify healthcheck is disabled', function () {
    $service = generateComposeServiceWithDockerfileHealthcheck([
        'health_check_enabled' => false,
    ]);

    expect($service)->not->toHaveKey('healthcheck');
});
