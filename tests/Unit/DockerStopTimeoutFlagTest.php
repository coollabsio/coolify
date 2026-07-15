<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationSetting;

function applicationWithDeploymentStopTimeout(): Application
{
    $settings = Mockery::mock(ApplicationSetting::class);
    $settings->shouldReceive('deploymentStopGracePeriodSeconds')->once()->andReturn(47);

    $application = new Application;
    $application->setRelation('settings', $settings);

    return $application;
}

it('uses the portable timeout flag during graceful deployment shutdown', function (bool $skipRemove, array $expectedCommands) {
    $application = applicationWithDeploymentStopTimeout();

    $job = new class(0) extends ApplicationDeploymentJob
    {
        public array $capturedCommands = [];

        public function __construct(int $applicationDeploymentQueueId)
        {
            $this->application_deployment_queue_id = $applicationDeploymentQueueId;
        }

        public function execute_remote_command(...$commands): void
        {
            $this->capturedCommands = $commands;
        }
    };

    $applicationProperty = new ReflectionProperty(ApplicationDeploymentJob::class, 'application');
    $applicationProperty->setValue($job, $application);

    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'graceful_shutdown_container');
    $method->invoke($job, 'deployment-container', $skipRemove);

    expect($job->capturedCommands)->toBe($expectedCommands);
})->with([
    'keep stopped container' => [
        true,
        [
            ['docker stop -t 47 deployment-container', 'hidden' => true, 'ignore_errors' => true],
        ],
    ],
    'remove stopped container' => [
        false,
        [
            ['docker stop -t 47 deployment-container', 'hidden' => true, 'ignore_errors' => true],
            ['docker rm -f deployment-container', 'hidden' => true, 'ignore_errors' => true],
        ],
    ],
]);

it('does not retain the deprecated timeout flag in affected command sources', function (string $sourceFile, int $expectedPortableFlagCount) {
    $source = file_get_contents(__DIR__.'/../../'.$sourceFile);

    expect($source)->not->toContain('docker stop --time')
        ->and(substr_count($source, 'docker stop -t $timeout'))->toBe($expectedPortableFlagCount);
})->with([
    'stop application' => ['app/Actions/Application/StopApplication.php', 1],
    'stop application on one server' => ['app/Actions/Application/StopApplicationOneServer.php', 1],
    'stop preview containers' => ['app/Livewire/Project/Application/Previews.php', 1],
    'graceful deployment shutdown' => ['app/Jobs/ApplicationDeploymentJob.php', 2],
]);
