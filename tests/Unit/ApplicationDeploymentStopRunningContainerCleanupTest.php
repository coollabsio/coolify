<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

class TestableStopContainerDeploymentJob extends ApplicationDeploymentJob
{
    public array $shutdownContainers = [];

    public Collection $fakeRunningContainers;

    public function __construct()
    {
        $this->fakeRunningContainers = collect([]);
    }

    protected function get_current_application_containers(): Collection
    {
        return $this->fakeRunningContainers;
    }

    protected function graceful_shutdown_container(string $containerName, bool $skipRemove = false)
    {
        $this->shutdownContainers[] = $containerName;
    }

    public function execute_remote_command(...$commands)
    {
        // no-op
    }
}

function makeStopContainerJob(array $settings, string $containerName, Collection $runningContainers): array
{
    $job = new TestableStopContainerDeploymentJob;
    $job->fakeRunningContainers = $runningContainers;

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $application = new Application;
    $application->setRelation('settings', new ApplicationSetting($settings));

    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'container_name' => $containerName,
        'pull_request_id' => 0,
        'newVersionIsHealthy' => true,
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection];
}

function invokeStopRunningContainer(object $job, ReflectionClass $reflection, bool $force = false): void
{
    $method = $reflection->getMethod('stop_running_container');
    $method->setAccessible(true);
    $method->invokeArgs($job, [$force]);
}

it('removes orphaned containers by label when consistent container name is enabled', function () {
    [$job, $reflection] = makeStopContainerJob(
        settings: ['is_consistent_container_name_enabled' => true],
        containerName: 'appuuid',
        runningContainers: collect([
            ['Names' => 'appuuid-142233123456'], // orphan from before the setting was enabled
            ['Names' => 'appuuid'],
        ]),
    );

    invokeStopRunningContainer($job, $reflection, force: true);

    expect($job->shutdownContainers)->toContain('appuuid')
        ->toContain('appuuid-142233123456');
});

it('removes orphaned containers by label when a custom internal name is set', function () {
    [$job, $reflection] = makeStopContainerJob(
        settings: ['custom_internal_name' => 'my-custom-name'],
        containerName: 'my-custom-name',
        runningContainers: collect([
            ['Names' => 'appuuid-142233123456'],
            ['Names' => 'old-custom-name'],
        ]),
    );

    invokeStopRunningContainer($job, $reflection, force: true);

    expect($job->shutdownContainers)->toContain('my-custom-name')
        ->toContain('appuuid-142233123456')
        ->toContain('old-custom-name');
});

it('does not shut down the new container twice with consistent naming', function () {
    [$job, $reflection] = makeStopContainerJob(
        settings: ['is_consistent_container_name_enabled' => true],
        containerName: 'appuuid',
        runningContainers: collect([
            ['Names' => 'appuuid'],
        ]),
    );

    invokeStopRunningContainer($job, $reflection, force: true);

    expect(array_count_values($job->shutdownContainers)['appuuid'])->toBe(1);
});

it('keeps label based cleanup for default naming', function () {
    [$job, $reflection] = makeStopContainerJob(
        settings: [],
        containerName: 'appuuid-999999999999',
        runningContainers: collect([
            ['Names' => 'appuuid-142233123456'],
            ['Names' => 'appuuid-999999999999'], // new container, must survive
        ]),
    );

    invokeStopRunningContainer($job, $reflection, force: true);

    expect($job->shutdownContainers)->toContain('appuuid-142233123456')
        ->not->toContain('appuuid-999999999999');
});
