<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;

/**
 * @return array<int, array<int|string, mixed>>
 */
function runFailedDeploymentCleanup(bool $consistentContainerName, ?string $customInternalName, int $pullRequestId): array
{
    $settings = new ApplicationSetting;
    $settings->setRawAttributes([
        'is_consistent_container_name_enabled' => $consistentContainerName,
        'custom_internal_name' => $customInternalName,
    ]);

    $application = new Application;
    $application->setRawAttributes(['build_pack' => 'dockerfile']);
    $application->setRelation('settings', $settings);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('failDeployment')->andReturnNull();

    $remoteCommands = [];
    $job->shouldReceive('execute_remote_command')->andReturnUsing(function (...$commands) use (&$remoteCommands) {
        array_push($remoteCommands, ...$commands);
    });

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getProperty('application_deployment_queue')->setValue($job, $queue);
    $reflection->getProperty('application')->setValue($job, $application);
    $reflection->getProperty('pull_request_id')->setValue($job, $pullRequestId);
    $reflection->getProperty('container_name')->setValue($job, 'new-container-20261001T120000');

    $job->failed(new RuntimeException('Health check failed'));

    return $remoteCommands;
}

it('removes the new rolling-update container when only a custom internal name is set', function () {
    $remoteCommands = runFailedDeploymentCleanup(consistentContainerName: false, customInternalName: 'my-api', pullRequestId: 0);

    expect($remoteCommands)->toHaveCount(1)
        ->and($remoteCommands[0][0])->toContain('new-container-20261001T120000');
});

it('removes the new rolling-update container when no custom name is set', function () {
    expect(runFailedDeploymentCleanup(consistentContainerName: false, customInternalName: null, pullRequestId: 0))->toHaveCount(1);
});

it('keeps the running container when consistent container names are enabled', function () {
    expect(runFailedDeploymentCleanup(consistentContainerName: true, customInternalName: 'my-api', pullRequestId: 0))->toBeEmpty();
});

it('keeps the running container for pull request deployments', function () {
    expect(runFailedDeploymentCleanup(consistentContainerName: false, customInternalName: 'my-api', pullRequestId: 12))->toBeEmpty();
});
