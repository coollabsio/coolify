<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationSetting;
use Illuminate\Support\Collection;

function containerNamingJob(Application $application, int $pullRequestId = 0): array
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getProperty('application')->setValue($job, $application);
    $reflection->getProperty('pull_request_id')->setValue($job, $pullRequestId);

    return [$job, $reflection];
}

function applicationWithContainerNaming(string $customName = 'shadowuw'): Application
{
    $application = new Application;
    $application->forceFill(['uuid' => 'application-uuid']);
    $application->setRelation('settings', new ApplicationSetting([
        'custom_internal_name' => $customName,
        'is_consistent_container_name_enabled' => true,
    ]));

    return $application;
}

it('uses the custom container name when consistent naming is enabled', function () {
    $application = applicationWithContainerNaming();

    [$job, $reflection] = containerNamingJob($application);

    expect($reflection->getMethod('resolveContainerName')->invoke($job))->toBe('shadowuw');
});

it('adds the pull request suffix to a custom container name', function () {
    $application = applicationWithContainerNaming();

    [$job, $reflection] = containerNamingJob($application, 42);

    expect($reflection->getMethod('resolveContainerName')->invoke($job))->toBe('shadowuw-pr-42');
});

it('includes old generated containers when cleaning up a consistent deployment', function () {
    $application = applicationWithContainerNaming();
    [$job, $reflection] = containerNamingJob($application);
    $reflection->getProperty('container_name')->setValue($job, 'shadowuw');

    $containers = new Collection([
        ['Names' => 'application-uuid-192238854305'],
        ['Names' => 'shadowuw'],
    ]);

    expect($reflection->getMethod('containerNamesToRemove')->invoke($job, $containers)->all())
        ->toBe(['application-uuid-192238854305', 'shadowuw']);
});
