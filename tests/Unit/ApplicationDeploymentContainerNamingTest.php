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
    expect(generateApplicationContainerName(applicationWithContainerNaming()))->toBe('shadowuw');
});

it('adds the pull request suffix to a custom container name', function () {
    expect(generateApplicationContainerName(applicationWithContainerNaming(), 42))->toBe('shadowuw-pr-42');
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

it('ignores the custom container name when consistent naming is disabled', function () {
    $application = applicationWithContainerNaming();
    $application->settings->is_consistent_container_name_enabled = false;

    expect(generateApplicationContainerName($application))->toStartWith('application-uuid-');
});

it('recognises generated container names in both timestamp formats', function () {
    expect(isGeneratedContainerName('application-uuid-20260908T141530'))->toBeTrue()
        ->and(isGeneratedContainerName('my-api-20260908T141530'))->toBeTrue()
        ->and(isGeneratedContainerName('application-uuid-192238854305'))->toBeTrue()
        ->and(isGeneratedContainerName('application-uuid'))->toBeFalse()
        ->and(isGeneratedContainerName('application-uuid-pr-42'))->toBeFalse()
        ->and(isGeneratedContainerName('my-api'))->toBeFalse();
});

function applicationWithContainerNamePrefix(string $prefix = 'my-api', bool $consistent = false): Application
{
    $application = new Application;
    $application->forceFill(['uuid' => 'application-uuid']);
    $application->setRelation('settings', new ApplicationSetting([
        'custom_container_name_prefix' => $prefix,
        'is_consistent_container_name_enabled' => $consistent,
    ]));

    return $application;
}

it('uses the container name prefix for generated container names only', function () {
    expect(generateApplicationContainerName(applicationWithContainerNamePrefix()))->toMatch('/^my-api-\d{8}T\d{6}$/')
        ->and(generateApplicationContainerName(applicationWithContainerNamePrefix('')))->toMatch('/^application-uuid-\d{8}T\d{6}$/')
        ->and(generateApplicationContainerName(applicationWithContainerNamePrefix(consistent: true)))->toBe('application-uuid')
        ->and(generateApplicationContainerName(applicationWithContainerNamePrefix(), 42))->toBe('application-uuid-pr-42');
});
