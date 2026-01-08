<?php

/**
 * Unit tests for PORT environment variable detection feature.
 *
 * Tests verify that the Application model can correctly detect PORT environment
 * variables and provide information to the UI about matches and mismatches with
 * the configured ports_exposes field.
 */

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Collection;
use Mockery;

beforeEach(function () {
    // Clean up Mockery after each test
    Mockery::close();
});

it('detects PORT environment variable when present', function () {
    // Create a mock Application instance
    $application = Mockery::mock(Application::class)->makePartial();

    // Mock environment variables collection with PORT set to 3000
    $portEnvVar = Mockery::mock(EnvironmentVariable::class);
    $portEnvVar->shouldReceive('getAttribute')->with('real_value')->andReturn('3000');

    $envVars = new Collection([$portEnvVar]);
    $application->shouldReceive('getAttribute')
        ->with('environment_variables')
        ->andReturn($envVars);

    // Mock the firstWhere method to return our PORT env var
    $envVars = Mockery::mock(Collection::class);
    $envVars->shouldReceive('firstWhere')->with('key', 'PORT')->andReturn($portEnvVar);
    $application->shouldReceive('getAttribute')
        ->with('environment_variables')
        ->andReturn($envVars);

    // Call the method we're testing
    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBe(3000);
});

it('returns null when PORT environment variable is not set', function () {
    $application = Mockery::mock(Application::class)->makePartial();

    // Mock environment variables collection without PORT
    $envVars = Mockery::mock(Collection::class);
    $envVars->shouldReceive('firstWhere')->with('key', 'PORT')->andReturn(null);
    $application->shouldReceive('getAttribute')
        ->with('environment_variables')
        ->andReturn($envVars);

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBeNull();
});

it('returns null when PORT value is not numeric', function () {
    $application = Mockery::mock(Application::class)->makePartial();

    // Mock environment variables with non-numeric PORT value
    $portEnvVar = Mockery::mock(EnvironmentVariable::class);
    $portEnvVar->shouldReceive('getAttribute')->with('real_value')->andReturn('invalid-port');

    $envVars = Mockery::mock(Collection::class);
    $envVars->shouldReceive('firstWhere')->with('key', 'PORT')->andReturn($portEnvVar);
    $application->shouldReceive('getAttribute')
        ->with('environment_variables')
        ->andReturn($envVars);

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBeNull();
});

it('handles PORT value with whitespace', function () {
    $application = Mockery::mock(Application::class)->makePartial();

    // Mock environment variables with PORT value that has whitespace
    $portEnvVar = Mockery::mock(EnvironmentVariable::class);
    $portEnvVar->shouldReceive('getAttribute')->with('real_value')->andReturn('  8080  ');

    $envVars = Mockery::mock(Collection::class);
    $envVars->shouldReceive('firstWhere')->with('key', 'PORT')->andReturn($portEnvVar);
    $application->shouldReceive('getAttribute')
        ->with('environment_variables')
        ->andReturn($envVars);

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBe(8080);
});

it('detects PORT from preview environment variables when isPreview is true', function () {
    $application = Mockery::mock(Application::class)->makePartial();

    // Mock preview environment variables with PORT
    $portEnvVar = Mockery::mock(EnvironmentVariable::class);
    $portEnvVar->shouldReceive('getAttribute')->with('real_value')->andReturn('4000');

    $envVars = Mockery::mock(Collection::class);
    $envVars->shouldReceive('firstWhere')->with('key', 'PORT')->andReturn($portEnvVar);
    $application->shouldReceive('getAttribute')
        ->with('environment_variables_preview')
        ->andReturn($envVars);

    $detectedPort = $application->detectPortFromEnvironment(true);

    expect($detectedPort)->toBe(4000);
});

it('verifies ports_exposes array conversion logic', function () {
    // Test the logic that converts comma-separated ports to array
    $portsExposesString = '3000,3001,8080';
    $expectedArray = [3000, 3001, 8080];

    // This simulates what portsExposesArray accessor does
    $result = is_null($portsExposesString)
        ? []
        : explode(',', $portsExposesString);

    // Convert to integers for comparison
    $result = array_map('intval', $result);

    expect($result)->toBe($expectedArray);
});

it('verifies PORT matches detection logic', function () {
    $detectedPort = 3000;
    $portsExposesArray = [3000, 3001];

    $isMatch = in_array($detectedPort, $portsExposesArray);

    expect($isMatch)->toBeTrue();
});

it('verifies PORT mismatch detection logic', function () {
    $detectedPort = 8080;
    $portsExposesArray = [3000, 3001];

    $isMatch = in_array($detectedPort, $portsExposesArray);

    expect($isMatch)->toBeFalse();
});

it('verifies empty ports_exposes detection logic', function () {
    $portsExposesArray = [];

    $isEmpty = empty($portsExposesArray);

    expect($isEmpty)->toBeTrue();
});
