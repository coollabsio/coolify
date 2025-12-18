<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;

/**
 * Test to verify that null and empty environment variables are filtered out
 * when generating Coolpack configuration.
 *
 * This test verifies that:
 * 1. User-defined environment variables with null or empty values are filtered out
 * 2. COOLIFY_* environment variables with null or empty values are filtered out
 * 3. Only environment variables with valid non-empty values are passed to Coolpack
 * 4. Coolpack uses --build-env flag instead of --env (unlike Nixpacks)
 */
it('filters out null environment variables from coolpack build command', function () {
    // Mock application with coolpack build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('coolpack');
    $mockApplication->build_pack = 'coolpack';

    // Mock environment variables - some with null/empty values
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'COOLPACK_NODE_VERSION';
    $envVar1->real_value = '24';

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'COOLPACK_NULL_VAR';
    $envVar2->real_value = null;

    $envVar3 = Mockery::mock(EnvironmentVariable::class);
    $envVar3->key = 'COOLPACK_EMPTY_VAR';
    $envVar3->real_value = '';

    $envVar4 = Mockery::mock(EnvironmentVariable::class);
    $envVar4->key = 'COOLPACK_CUSTOM';
    $envVar4->real_value = 'custom_value';

    $coolpackEnvVars = collect([$envVar1, $envVar2, $envVar3, $envVar4]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('coolpack_environment_variables')
        ->andReturn($coolpackEnvVars);

    // Mock application deployment queue
    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $mockQueue->shouldReceive('getAttribute')->with('application_id')->andReturn(1);
    $mockQueue->application_id = 1;

    // Mock the job
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass(ApplicationDeploymentJob::class);

    // Set private properties
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    // Mock generate_coolify_env_variables to return some values including null
    $job->shouldReceive('generate_coolify_env_variables')
        ->andReturn(collect([
            'COOLIFY_FQDN' => 'example.com',
            'COOLIFY_URL' => null,  // null value that should be filtered
            'COOLIFY_BRANCH' => '',  // empty value that should be filtered
            'SOURCE_COMMIT' => 'abc123',
        ]));

    // Call the private method
    $method = $reflection->getMethod('generate_coolpack_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_coolpack_args
    $envArgsProperty = $reflection->getProperty('env_coolpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that only valid environment variables are included with --build-env flag
    expect($envArgs)->toContain('--build-env COOLPACK_NODE_VERSION=24');
    expect($envArgs)->toContain('--build-env COOLPACK_CUSTOM=custom_value');
    expect($envArgs)->toContain('--build-env COOLIFY_FQDN=example.com');
    expect($envArgs)->toContain('--build-env SOURCE_COMMIT=abc123');

    // Verify that null and empty environment variables are filtered out
    expect($envArgs)->not->toContain('COOLPACK_NULL_VAR');
    expect($envArgs)->not->toContain('COOLPACK_EMPTY_VAR');
    expect($envArgs)->not->toContain('COOLIFY_URL');
    expect($envArgs)->not->toContain('COOLIFY_BRANCH');

    // Verify no environment variables end with just '=' (which indicates null/empty value)
    expect($envArgs)->not->toMatch('/--build-env [A-Z_]+=$/');
    expect($envArgs)->not->toMatch('/--build-env [A-Z_]+= /');
});

it('filters out null environment variables from coolpack preview deployments', function () {
    // Mock application with coolpack build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('coolpack');
    $mockApplication->build_pack = 'coolpack';

    // Mock preview environment variables - some with null/empty values
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'COOLPACK_NODE_VERSION';
    $envVar1->real_value = '22';

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'COOLPACK_NULL_PREVIEW';
    $envVar2->real_value = null;

    $previewEnvVars = collect([$envVar1, $envVar2]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('coolpack_environment_variables_preview')
        ->andReturn($previewEnvVars);

    // Mock application deployment queue
    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $mockQueue->shouldReceive('getAttribute')->with('application_id')->andReturn(1);
    $mockQueue->application_id = 1;

    // Mock the job
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass(ApplicationDeploymentJob::class);

    // Set private properties
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 123);  // Non-zero for preview deployment

    // Mock generate_coolify_env_variables
    $job->shouldReceive('generate_coolify_env_variables')
        ->andReturn(collect([
            'COOLIFY_FQDN' => 'preview.example.com',
        ]));

    // Call the private method
    $method = $reflection->getMethod('generate_coolpack_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_coolpack_args
    $envArgsProperty = $reflection->getProperty('env_coolpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that only valid environment variables are included
    expect($envArgs)->toContain('--build-env COOLPACK_NODE_VERSION=22');
    expect($envArgs)->toContain('--build-env COOLIFY_FQDN=preview.example.com');

    // Verify that null environment variables are filtered out
    expect($envArgs)->not->toContain('COOLPACK_NULL_PREVIEW');
});

it('handles all coolpack environment variables being null or empty', function () {
    // Mock application with coolpack build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('coolpack');
    $mockApplication->build_pack = 'coolpack';

    // Mock environment variables - all null or empty
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'COOLPACK_NULL_VAR';
    $envVar1->real_value = null;

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'COOLPACK_EMPTY_VAR';
    $envVar2->real_value = '';

    $coolpackEnvVars = collect([$envVar1, $envVar2]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('coolpack_environment_variables')
        ->andReturn($coolpackEnvVars);

    // Mock application deployment queue
    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $mockQueue->shouldReceive('getAttribute')->with('application_id')->andReturn(1);
    $mockQueue->application_id = 1;

    // Mock the job
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass(ApplicationDeploymentJob::class);

    // Set private properties
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    // Mock generate_coolify_env_variables to return all null/empty values
    $job->shouldReceive('generate_coolify_env_variables')
        ->andReturn(collect([
            'COOLIFY_URL' => null,
            'COOLIFY_BRANCH' => '',
        ]));

    // Call the private method
    $method = $reflection->getMethod('generate_coolpack_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_coolpack_args
    $envArgsProperty = $reflection->getProperty('env_coolpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that the result is empty or contains no environment variables
    expect($envArgs)->toBe('');
});

it('preserves coolpack environment variables with zero values', function () {
    // Mock application with coolpack build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('coolpack');
    $mockApplication->build_pack = 'coolpack';

    // Mock environment variables with zero values (which should NOT be filtered)
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'COOLPACK_ZERO_VALUE';
    $envVar1->real_value = '0';

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'COOLPACK_FALSE_VALUE';
    $envVar2->real_value = 'false';

    $coolpackEnvVars = collect([$envVar1, $envVar2]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('coolpack_environment_variables')
        ->andReturn($coolpackEnvVars);

    // Mock application deployment queue
    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $mockQueue->shouldReceive('getAttribute')->with('application_id')->andReturn(1);
    $mockQueue->application_id = 1;

    // Mock the job
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass(ApplicationDeploymentJob::class);

    // Set private properties
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    // Mock generate_coolify_env_variables
    $job->shouldReceive('generate_coolify_env_variables')
        ->andReturn(collect([]));

    // Call the private method
    $method = $reflection->getMethod('generate_coolpack_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_coolpack_args
    $envArgsProperty = $reflection->getProperty('env_coolpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that zero and false string values are preserved
    expect($envArgs)->toContain('--build-env COOLPACK_ZERO_VALUE=0');
    expect($envArgs)->toContain('--build-env COOLPACK_FALSE_VALUE=false');
});

it('uses --build-env flag for coolpack instead of --env', function () {
    // This test verifies that coolpack uses the correct --build-env flag
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('coolpack');
    $mockApplication->build_pack = 'coolpack';

    $envVar = Mockery::mock(EnvironmentVariable::class);
    $envVar->key = 'COOLPACK_NODE_VERSION';
    $envVar->real_value = '24';

    $coolpackEnvVars = collect([$envVar]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('coolpack_environment_variables')
        ->andReturn($coolpackEnvVars);

    $mockQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $mockQueue->shouldReceive('getAttribute')->with('application_id')->andReturn(1);
    $mockQueue->application_id = 1;

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new \ReflectionClass(ApplicationDeploymentJob::class);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $mockApplication);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $job->shouldReceive('generate_coolify_env_variables')
        ->andReturn(collect([]));

    $method = $reflection->getMethod('generate_coolpack_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    $envArgsProperty = $reflection->getProperty('env_coolpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify --build-env is used, not --env
    expect($envArgs)->toContain('--build-env');
    expect($envArgs)->not->toContain('--env ');
});
