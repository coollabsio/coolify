<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;

/**
 * Test to verify that null and empty environment variables are filtered out
 * when generating Nixpacks configuration.
 *
 * This test verifies the fix for the issue where null or empty environment variable
 * values would be passed to Nixpacks as `--env KEY=` (with no value), causing
 * JSON parsing errors: "invalid type: null, expected a string at line 12 column 27"
 *
 * The fix ensures that:
 * 1. User-defined environment variables with null or empty values are filtered out
 * 2. COOLIFY_* environment variables with null or empty values are filtered out
 * 3. Only environment variables with valid non-empty values are passed to Nixpacks
 */
it('filters out null environment variables from nixpacks build command', function () {
    // Mock application with nixpacks build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('nixpacks');
    $mockApplication->build_pack = 'nixpacks';

    // Mock environment variables - some with null/empty values
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'VALID_VAR';
    $envVar1->real_value = 'valid_value';

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'NULL_VAR';
    $envVar2->real_value = null;

    $envVar3 = Mockery::mock(EnvironmentVariable::class);
    $envVar3->key = 'EMPTY_VAR';
    $envVar3->real_value = '';

    $envVar4 = Mockery::mock(EnvironmentVariable::class);
    $envVar4->key = 'ANOTHER_VALID_VAR';
    $envVar4->real_value = 'another_value';

    $nixpacksEnvVars = collect([$envVar1, $envVar2, $envVar3, $envVar4]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('nixpacks_environment_variables')
        ->andReturn($nixpacksEnvVars);

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
    $method = $reflection->getMethod('generate_nixpacks_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_nixpacks_args
    $envArgsProperty = $reflection->getProperty('env_nixpacks_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that only valid environment variables are included
    expect($envArgs)->toContain('--env VALID_VAR=valid_value');
    expect($envArgs)->toContain('--env ANOTHER_VALID_VAR=another_value');
    expect($envArgs)->toContain('--env COOLIFY_FQDN=example.com');
    expect($envArgs)->toContain('--env SOURCE_COMMIT=abc123');

    // Verify that null and empty environment variables are filtered out
    expect($envArgs)->not->toContain('NULL_VAR');
    expect($envArgs)->not->toContain('EMPTY_VAR');
    expect($envArgs)->not->toContain('COOLIFY_URL');
    expect($envArgs)->not->toContain('COOLIFY_BRANCH');

    // Verify no environment variables end with just '=' (which indicates null/empty value)
    expect($envArgs)->not->toMatch('/--env [A-Z_]+=$/');
    expect($envArgs)->not->toMatch('/--env [A-Z_]+= /');
});

it('filters out null environment variables from nixpacks preview deployments', function () {
    // Mock application with nixpacks build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('nixpacks');
    $mockApplication->build_pack = 'nixpacks';

    // Mock preview environment variables - some with null/empty values
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'PREVIEW_VAR';
    $envVar1->real_value = 'preview_value';

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'NULL_PREVIEW_VAR';
    $envVar2->real_value = null;

    $previewEnvVars = collect([$envVar1, $envVar2]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('nixpacks_environment_variables_preview')
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
    $method = $reflection->getMethod('generate_nixpacks_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_nixpacks_args
    $envArgsProperty = $reflection->getProperty('env_nixpacks_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that only valid environment variables are included
    expect($envArgs)->toContain('--env PREVIEW_VAR=preview_value');
    expect($envArgs)->toContain('--env COOLIFY_FQDN=preview.example.com');

    // Verify that null environment variables are filtered out
    expect($envArgs)->not->toContain('NULL_PREVIEW_VAR');
});

it('handles all environment variables being null or empty', function () {
    // Mock application with nixpacks build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('nixpacks');
    $mockApplication->build_pack = 'nixpacks';

    // Mock environment variables - all null or empty
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'NULL_VAR';
    $envVar1->real_value = null;

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'EMPTY_VAR';
    $envVar2->real_value = '';

    $nixpacksEnvVars = collect([$envVar1, $envVar2]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('nixpacks_environment_variables')
        ->andReturn($nixpacksEnvVars);

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
    $method = $reflection->getMethod('generate_nixpacks_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_nixpacks_args
    $envArgsProperty = $reflection->getProperty('env_nixpacks_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that the result is empty or contains no environment variables
    expect($envArgs)->toBe('');
});

it('preserves environment variables with zero values', function () {
    // Mock application with nixpacks build pack
    $mockApplication = Mockery::mock(Application::class);
    $mockApplication->shouldReceive('getAttribute')
        ->with('build_pack')
        ->andReturn('nixpacks');
    $mockApplication->build_pack = 'nixpacks';

    // Mock environment variables with zero values (which should NOT be filtered)
    $envVar1 = Mockery::mock(EnvironmentVariable::class);
    $envVar1->key = 'ZERO_VALUE';
    $envVar1->real_value = '0';

    $envVar2 = Mockery::mock(EnvironmentVariable::class);
    $envVar2->key = 'FALSE_VALUE';
    $envVar2->real_value = 'false';

    $nixpacksEnvVars = collect([$envVar1, $envVar2]);

    $mockApplication->shouldReceive('getAttribute')
        ->with('nixpacks_environment_variables')
        ->andReturn($nixpacksEnvVars);

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
    $method = $reflection->getMethod('generate_nixpacks_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    // Get the generated env_nixpacks_args
    $envArgsProperty = $reflection->getProperty('env_nixpacks_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    // Verify that zero and false string values are preserved
    expect($envArgs)->toContain('--env ZERO_VALUE=0');
    expect($envArgs)->toContain('--env FALSE_VALUE=false');
});
