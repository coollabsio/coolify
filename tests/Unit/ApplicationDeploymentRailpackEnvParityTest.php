<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;

it('generates escaped railpack env args from resolved values and includes install command', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('install_command')->andReturn('npm ci && npm run postinstall');

    $nodeVersion = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $nodeVersion->forceFill([
        'key' => 'RAILPACK_NODE_VERSION',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $nodeVersion->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('22');

    $literalValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $literalValue->forceFill([
        'key' => 'RAILPACK_CUSTOM_FLAG',
        'is_literal' => true,
        'is_multiline' => false,
    ]);
    $literalValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn("'hello world'");

    $jsonValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $jsonValue->forceFill([
        'key' => 'RAILPACK_JSON',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $jsonValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('{"token":"abc"}');

    $nullValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $nullValue->forceFill([
        'key' => 'RAILPACK_NULL',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $nullValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn(null);

    $envQuery = Mockery::mock();
    $envQuery->shouldReceive('withoutBuildpackControlVariables')->once()->andReturnSelf();
    $envQuery->shouldReceive('where')->with('is_buildtime', true)->once()->andReturnSelf();
    $envQuery->shouldReceive('get')->once()->andReturn(collect([]));
    $application->shouldReceive('environment_variables')->once()->andReturn($envQuery);

    $railpackQuery = Mockery::mock();
    $railpackQuery->shouldReceive('get')->once()->andReturn(collect([$nodeVersion, $literalValue, $jsonValue, $nullValue]));
    $application->shouldReceive('railpack_environment_variables')->once()->andReturn($railpackQuery);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('generate_coolify_env_variables')->andReturn(collect([]));

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $mainServerProperty = $reflection->getProperty('mainServer');
    $mainServerProperty->setAccessible(true);
    $mainServerProperty->setValue($job, Mockery::mock(Server::class));

    $method = $reflection->getMethod('generate_railpack_env_variables');
    $method->setAccessible(true);
    $variables = $method->invoke($job);

    $envArgsProperty = $reflection->getProperty('env_railpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    expect($variables->all())->toBe([
        'RAILPACK_NODE_VERSION' => '22',
        'RAILPACK_CUSTOM_FLAG' => 'hello world',
        'RAILPACK_JSON' => '{"token":"abc"}',
        'RAILPACK_INSTALL_CMD' => 'npm ci && npm run postinstall',
        'RAILPACK_DEPLOY_APT_PACKAGES' => 'curl wget',
    ]);
    expect($envArgs)->toContain("--env 'RAILPACK_NODE_VERSION=22'");
    expect($envArgs)->toContain("--env 'RAILPACK_CUSTOM_FLAG=hello world'");
    expect($envArgs)->toContain("--env 'RAILPACK_JSON={\"token\":\"abc\"}'");
    expect($envArgs)->toContain("--env 'RAILPACK_INSTALL_CMD=npm ci && npm run postinstall'");
    expect($envArgs)->toContain("--env 'RAILPACK_DEPLOY_APT_PACKAGES=curl wget'");
    expect($envArgs)->not->toContain('RAILPACK_NULL');
});

it('uses preview railpack environment variables for preview deployments', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('install_command')->andReturn(null);

    $previewValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $previewValue->forceFill([
        'key' => 'RAILPACK_PREVIEW_ONLY',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $previewValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('preview-value');

    $previewQuery = Mockery::mock();
    $previewQuery->shouldReceive('withoutBuildpackControlVariables')->once()->andReturnSelf();
    $previewQuery->shouldReceive('where')->with('is_buildtime', true)->once()->andReturnSelf();
    $previewQuery->shouldReceive('get')->once()->andReturn(collect([]));
    $application->shouldReceive('environment_variables_preview')->once()->andReturn($previewQuery);

    $railpackPreviewQuery = Mockery::mock();
    $railpackPreviewQuery->shouldReceive('get')->once()->andReturn(collect([$previewValue]));
    $application->shouldReceive('railpack_environment_variables_preview')->once()->andReturn($railpackPreviewQuery);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('generate_coolify_env_variables')->andReturn(collect([]));

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 42);

    $mainServerProperty = $reflection->getProperty('mainServer');
    $mainServerProperty->setAccessible(true);
    $mainServerProperty->setValue($job, Mockery::mock(Server::class));

    $method = $reflection->getMethod('generate_railpack_env_variables');
    $method->setAccessible(true);
    $variables = $method->invoke($job);

    expect($variables->all())->toBe([
        'RAILPACK_PREVIEW_ONLY' => 'preview-value',
        'RAILPACK_DEPLOY_APT_PACKAGES' => 'curl wget',
    ]);
});

it('merges coolify env variables into railpack build variables', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('install_command')->andReturn(null);

    $userVar = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $userVar->forceFill([
        'key' => 'MY_BUILD_VAR',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $userVar->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('hello');

    $envQuery = Mockery::mock();
    $envQuery->shouldReceive('withoutBuildpackControlVariables')->once()->andReturnSelf();
    $envQuery->shouldReceive('where')->with('is_buildtime', true)->once()->andReturnSelf();
    $envQuery->shouldReceive('get')->once()->andReturn(collect([$userVar]));
    $application->shouldReceive('environment_variables')->once()->andReturn($envQuery);

    $railpackQuery = Mockery::mock();
    $railpackQuery->shouldReceive('get')->once()->andReturn(collect([]));
    $application->shouldReceive('railpack_environment_variables')->once()->andReturn($railpackQuery);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('generate_coolify_env_variables')
        ->with(true)
        ->andReturn(collect([
            'COOLIFY_URL' => 'https://app.example.com',
            'COOLIFY_FQDN' => 'app.example.com',
            'COOLIFY_BRANCH' => 'main',
            'COOLIFY_RESOURCE_UUID' => 'app-uuid',
            'SOURCE_COMMIT' => 'abc123',
            'EMPTY_VAR' => '',
            'NULL_VAR' => null,
        ]));

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $mainServerProperty = $reflection->getProperty('mainServer');
    $mainServerProperty->setAccessible(true);
    $mainServerProperty->setValue($job, Mockery::mock(Server::class));

    $method = $reflection->getMethod('generate_railpack_env_variables');
    $method->setAccessible(true);
    $variables = $method->invoke($job);

    expect($variables->all())->toBe([
        'MY_BUILD_VAR' => 'hello',
        'RAILPACK_DEPLOY_APT_PACKAGES' => 'curl wget',
        'COOLIFY_URL' => 'https://app.example.com',
        'COOLIFY_FQDN' => 'app.example.com',
        'COOLIFY_BRANCH' => 'main',
        'COOLIFY_RESOURCE_UUID' => 'app-uuid',
        'SOURCE_COMMIT' => 'abc123',
    ]);

    $envArgsProperty = $reflection->getProperty('env_railpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    expect($envArgs)->toContain("--env 'COOLIFY_URL=https://app.example.com'");
    expect($envArgs)->toContain("--env 'SOURCE_COMMIT=abc123'");
    expect($envArgs)->toContain("--env 'RAILPACK_DEPLOY_APT_PACKAGES=curl wget'");
    expect($envArgs)->not->toContain('EMPTY_VAR');
    expect($envArgs)->not->toContain('NULL_VAR');
});

it('preserves user railpack deploy apt packages while adding healthcheck tools once', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('install_command')->andReturn(null);

    $deployPackages = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $deployPackages->forceFill([
        'key' => 'RAILPACK_DEPLOY_APT_PACKAGES',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $deployPackages->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('ffmpeg curl');

    $envQuery = Mockery::mock();
    $envQuery->shouldReceive('withoutBuildpackControlVariables')->once()->andReturnSelf();
    $envQuery->shouldReceive('where')->with('is_buildtime', true)->once()->andReturnSelf();
    $envQuery->shouldReceive('get')->once()->andReturn(collect([]));
    $application->shouldReceive('environment_variables')->once()->andReturn($envQuery);

    $railpackQuery = Mockery::mock();
    $railpackQuery->shouldReceive('get')->once()->andReturn(collect([$deployPackages]));
    $application->shouldReceive('railpack_environment_variables')->once()->andReturn($railpackQuery);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('generate_coolify_env_variables')->andReturn(collect([]));

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $mainServerProperty = $reflection->getProperty('mainServer');
    $mainServerProperty->setAccessible(true);
    $mainServerProperty->setValue($job, Mockery::mock(Server::class));

    $method = $reflection->getMethod('generate_railpack_env_variables');
    $method->setAccessible(true);
    $variables = $method->invoke($job);

    expect($variables->get('RAILPACK_DEPLOY_APT_PACKAGES'))->toBe('ffmpeg curl wget');

    $envArgsProperty = $reflection->getProperty('env_railpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    expect($envArgs)->toContain("--env 'RAILPACK_DEPLOY_APT_PACKAGES=ffmpeg curl wget'");
});
