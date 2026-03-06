<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Collection;

function applicationDeploymentServerEnvKeysCollection(array $keys): Collection
{
    return collect($keys)->map(fn (string $key) => (object) ['key' => $key]);
}

function buildApplicationDeploymentJobForServerEnvVariablesTest(Collection $environmentVariables, int $pullRequestId = 0): ApplicationDeploymentJob
{
    $settings = (object) ['include_source_commit_in_build' => false];

    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->andReturnUsing(function (string $key) use ($environmentVariables, $settings) {
        return match ($key) {
            'environment_variables' => $environmentVariables,
            'environment_variables_preview' => $environmentVariables,
            'compose_parsing_version' => '2',
            'build_pack' => 'dockerfile',
            'fqdn' => 'https://app.example.com',
            'uuid' => 'app-uuid',
            'settings' => $settings,
            default => null,
        };
    });

    $server = new Server;
    $server->forceFill([
        'id' => 42,
        'name' => 'server-chi',
        'uuid' => 'srv-uuid-42',
    ]);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, $pullRequestId);

    $branchProperty = $reflection->getProperty('branch');
    $branchProperty->setAccessible(true);
    $branchProperty->setValue($job, 'main');

    $commitProperty = $reflection->getProperty('commit');
    $commitProperty->setAccessible(true);
    $commitProperty->setValue($job, 'abc123');

    $containerNameProperty = $reflection->getProperty('container_name');
    $containerNameProperty->setAccessible(true);
    $containerNameProperty->setValue($job, 'coolify-test-container');

    $serverProperty = $reflection->getProperty('server');
    $serverProperty->setAccessible(true);
    $serverProperty->setValue($job, $server);

    return $job;
}

function callGenerateCoolifyEnvVariablesForServerEnvTest(ApplicationDeploymentJob $job, bool $forBuildTime): Collection
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $method = $reflection->getMethod('generate_coolify_env_variables');
    $method->setAccessible(true);

    return $method->invoke($job, $forBuildTime);
}

it('injects server metadata environment variables at runtime when keys are not overridden', function () {
    $job = buildApplicationDeploymentJobForServerEnvVariablesTest(applicationDeploymentServerEnvKeysCollection([]));
    $coolifyEnvs = callGenerateCoolifyEnvVariablesForServerEnvTest($job, false);

    expect($coolifyEnvs->get('COOLIFY_SERVER_NAME'))->toBe('server-chi');
    expect($coolifyEnvs->get('COOLIFY_SERVER_UUID'))->toBe('srv-uuid-42');
});

it('does not inject server metadata environment variables when application overrides keys', function () {
    $job = buildApplicationDeploymentJobForServerEnvVariablesTest(applicationDeploymentServerEnvKeysCollection([
        'COOLIFY_SERVER_NAME',
        'COOLIFY_SERVER_UUID',
    ]));
    $coolifyEnvs = callGenerateCoolifyEnvVariablesForServerEnvTest($job, false);

    expect($coolifyEnvs->has('COOLIFY_SERVER_NAME'))->toBeFalse();
    expect($coolifyEnvs->has('COOLIFY_SERVER_UUID'))->toBeFalse();
});

it('does not inject server metadata environment variables into build-time env generation', function () {
    $job = buildApplicationDeploymentJobForServerEnvVariablesTest(applicationDeploymentServerEnvKeysCollection([]));
    $coolifyEnvs = callGenerateCoolifyEnvVariablesForServerEnvTest($job, true);

    expect($coolifyEnvs->has('COOLIFY_SERVER_NAME'))->toBeFalse();
    expect($coolifyEnvs->has('COOLIFY_SERVER_UUID'))->toBeFalse();
});
