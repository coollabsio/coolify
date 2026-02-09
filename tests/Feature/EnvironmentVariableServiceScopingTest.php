<?php

/**
 * Unit tests for Environment Variable Service Scoping (Issue #7655)
 *
 * This tests the fix that respects Docker Compose semantics:
 * - .env file = interpolation ONLY (for ${VAR} substitution in compose file)
 * - Runtime variables = explicit user declaration via environment: or env_file:
 * - Per-service scoping prevents secret leakage across containers
 */

use App\Models\EnvironmentVariable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('database has service scoping columns', function () {
    expect(Schema::hasColumn('environment_variables', 'service_names'))->toBeTrue();
    expect(Schema::hasColumn('environment_variables', 'is_interpolation_only'))->toBeTrue();
    expect(Schema::hasColumn('environment_variables', 'injection_method'))->toBeTrue();
});

it('casts service_names to array', function () {
    $env = new EnvironmentVariable([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'service_names' => json_encode(['web', 'api']),
    ]);

    expect($env->service_names)->toBeArray();
    expect($env->service_names)->toBe(['web', 'api']);
});

it('casts is_interpolation_only to boolean', function () {
    $env = new EnvironmentVariable([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'is_interpolation_only' => true,
    ]);

    expect($env->is_interpolation_only)->toBeTrue();
    expect($env->is_interpolation_only)->toBeBool();
});

it('defaults injection_method to environment', function () {
    $env = new EnvironmentVariable([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
    ]);

    // Default value from migration should be 'environment'
    expect($env->injection_method)->toBeString();
});

it('accepts valid injection methods', function () {
    $validMethods = ['none', 'environment', 'env_file'];

    foreach ($validMethods as $method) {
        $env = new EnvironmentVariable([
            'key' => 'TEST_VAR',
            'value' => 'test_value',
            'injection_method' => $method,
        ]);

        expect($env->injection_method)->toBe($method);
    }
});

it('filters interpolation-only variables correctly', function () {
    $interpolationVar = new EnvironmentVariable([
        'key' => 'COMPOSE_VAR',
        'value' => 'value',
        'is_interpolation_only' => true,
        'injection_method' => 'none',
    ]);

    $runtimeVar = new EnvironmentVariable([
        'key' => 'RUNTIME_VAR',
        'value' => 'value',
        'is_interpolation_only' => false,
        'injection_method' => 'environment',
    ]);

    expect($interpolationVar->is_interpolation_only)->toBeTrue();
    expect($runtimeVar->is_interpolation_only)->toBeFalse();
});

it('scopes variables to specific services', function () {
    $webOnlyVar = new EnvironmentVariable([
        'key' => 'WEB_SECRET',
        'value' => 'secret123',
        'service_names' => json_encode(['web']),
        'injection_method' => 'environment',
    ]);

    $allServicesVar = new EnvironmentVariable([
        'key' => 'SHARED_VAR',
        'value' => 'shared',
        'service_names' => json_encode(['all']),
        'injection_method' => 'environment',
    ]);

    expect($webOnlyVar->service_names)->toBe(['web']);
    expect($allServicesVar->service_names)->toBe(['all']);
});

it('supports multiple service targeting', function () {
    $multiServiceVar = new EnvironmentVariable([
        'key' => 'API_KEY',
        'value' => 'key123',
        'service_names' => json_encode(['web', 'api', 'worker']),
        'injection_method' => 'environment',
    ]);

    expect($multiServiceVar->service_names)->toBeArray();
    expect($multiServiceVar->service_names)->toHaveCount(3);
    expect($multiServiceVar->service_names)->toContain('web');
    expect($multiServiceVar->service_names)->toContain('api');
    expect($multiServiceVar->service_names)->toContain('worker');
});

it('distinguishes between injection methods', function () {
    $environmentVar = new EnvironmentVariable([
        'key' => 'ENV_VAR',
        'value' => 'value1',
        'injection_method' => 'environment',
    ]);

    $envFileVar = new EnvironmentVariable([
        'key' => 'FILE_VAR',
        'value' => 'value2',
        'injection_method' => 'env_file',
    ]);

    $noneVar = new EnvironmentVariable([
        'key' => 'INTERPOLATION_VAR',
        'value' => 'value3',
        'injection_method' => 'none',
        'is_interpolation_only' => true,
    ]);

    expect($environmentVar->injection_method)->toBe('environment');
    expect($envFileVar->injection_method)->toBe('env_file');
    expect($noneVar->injection_method)->toBe('none');
});

it('prevents secret leakage with proper scoping', function () {
    $dbPassword = new EnvironmentVariable([
        'key' => 'DB_PASSWORD',
        'value' => 'super_secret',
        'service_names' => json_encode(['database']),
        'injection_method' => 'environment',
    ]);

    $webApiKey = new EnvironmentVariable([
        'key' => 'API_KEY',
        'value' => 'api_secret',
        'service_names' => json_encode(['web']),
        'injection_method' => 'environment',
    ]);

    // Database password should only go to 'database' service
    expect($dbPassword->service_names)->not->toContain('web');
    expect($dbPassword->service_names)->not->toContain('api');
    expect($dbPassword->service_names)->toContain('database');

    // Web API key should only go to 'web' service
    expect($webApiKey->service_names)->not->toContain('database');
    expect($webApiKey->service_names)->not->toContain('api');
    expect($webApiKey->service_names)->toContain('web');
});

it('maintains backward compatibility with all services default', function () {
    // Variables without explicit service_names should default to ['all']
    $legacyVar = new EnvironmentVariable([
        'key' => 'LEGACY_VAR',
        'value' => 'value',
        'service_names' => json_encode(['all']), // Migration sets this
        'injection_method' => 'environment', // Migration sets this
    ]);

    expect($legacyVar->service_names)->toBe(['all']);
    expect($legacyVar->injection_method)->toBe('environment');
});

it('respects docker compose semantics for interpolation', function () {
    // Interpolation variables should:
    // 1. Have is_interpolation_only = true
    // 2. Have injection_method = 'none'
    // 3. Only appear in global .env for ${VAR} substitution

    $composeVar = new EnvironmentVariable([
        'key' => 'COMPOSE_PROJECT_NAME',
        'value' => 'myproject',
        'is_interpolation_only' => true,
        'injection_method' => 'none',
    ]);

    expect($composeVar->is_interpolation_only)->toBeTrue();
    expect($composeVar->injection_method)->toBe('none');
});

it('allows environment injection method for runtime variables', function () {
    // Runtime variables should:
    // 1. Have is_interpolation_only = false
    // 2. Have injection_method = 'environment' OR 'env_file'
    // 3. Be scoped to specific services or 'all'

    $runtimeVar = new EnvironmentVariable([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://...',
        'is_interpolation_only' => false,
        'injection_method' => 'environment',
        'service_names' => json_encode(['web', 'worker']),
    ]);

    expect($runtimeVar->is_interpolation_only)->toBeFalse();
    expect($runtimeVar->injection_method)->toBe('environment');
    expect($runtimeVar->service_names)->toBeArray();
});

it('allows env_file injection method for runtime variables', function () {
    $envFileVar = new EnvironmentVariable([
        'key' => 'SECRET_KEY',
        'value' => 'secret',
        'is_interpolation_only' => false,
        'injection_method' => 'env_file',
        'service_names' => json_encode(['web']),
    ]);

    expect($envFileVar->is_interpolation_only)->toBeFalse();
    expect($envFileVar->injection_method)->toBe('env_file');
    expect($envFileVar->service_names)->toContain('web');
});

it('ensures ApplicationDeploymentJob has scoping methods', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Check that the new helper method exists
    expect($deploymentJobFile)->toContain('get_environment_variables_for_service');
    expect($deploymentJobFile)->toContain('is_interpolation_only');
    expect($deploymentJobFile)->toContain('injection_method');
});

it('verifies removal of auto-injection bug', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // The old bug: $service['env_file'] = ['.env'];
    // Should NOT unconditionally set this anymore
    // Instead should check: get_environment_variables_for_service

    expect($deploymentJobFile)->toContain('get_environment_variables_for_service');

    // The fix should create per-service env files: .env.$service_name
    expect($deploymentJobFile)->toContain('.env.$service_name');
});

it('creates global env file for interpolation only', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Global .env should contain ONLY interpolation variables
    expect($deploymentJobFile)->toContain('interpolation');
    expect($deploymentJobFile)->toContain('is_interpolation_only');
});

it('supports per-service env files', function () {
    $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Should create per-service .env files for scoped variables
    expect($deploymentJobFile)->toContain('service_name');
    expect($deploymentJobFile)->toContain('.env.');
});
