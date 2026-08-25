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

class TestableCustomDockerOptionsDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}

    public function execute_remote_command(...$commands): void {}
}

function generateComposeServiceWithCustomDockerOptions(string $customDockerOptions): array
{
    $team = Team::create([
        'name' => 'Custom Docker Options Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $project = Project::create([
        'name' => 'Custom Docker Options Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerimage',
        'custom_docker_run_options' => $customDockerOptions,
        'custom_network_aliases' => json_encode(['custom-alias'], JSON_THROW_ON_ERROR),
    ]);
    $application->settings()->update([
        'is_consistent_container_name_enabled' => true,
        'custom_internal_name' => 'custom-internal-name',
    ]);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->status = 'queued';
    $queue->shouldReceive('refresh')->once()->andReturnSelf();

    $application = $application->fresh();
    $job = new TestableCustomDockerOptionsDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'destination' => $destination,
        'server' => $server,
        'mainServer' => $server,
        'pull_request_id' => 0,
        'container_name' => $application->uuid,
        'production_image_name' => 'example/app:latest',
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/custom-docker-options-app',
        'configuration_dir' => '/data/coolify/applications/test',
        'saved_outputs' => new Collection,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $reflection->getMethod('generate_compose_file')->invoke($job);
    $compose = Yaml::parse($reflection->getProperty('docker_compose')->getValue($job));

    return $compose['services'][$application->uuid];
}

it('applies an entrypoint when consistent naming and a custom internal name are configured', function () {
    expect(generateComposeServiceWithCustomDockerOptions('--entrypoint "/bin/echo hello world"'))
        ->toHaveKey('entrypoint', '/bin/echo hello world');
});

it('preserves custom network aliases when a static IP is configured', function () {
    $service = generateComposeServiceWithCustomDockerOptions('--ip 10.0.0.25 --ip6 2001:db8::25');
    $network = $service['networks'][array_key_first($service['networks'])];

    expect($network)
        ->toHaveKey('ipv4_address', '10.0.0.25')
        ->toHaveKey('ipv6_address', '2001:db8::25')
        ->toHaveKey('aliases')
        ->and($network['aliases'])->toContain('custom-alias');
});
