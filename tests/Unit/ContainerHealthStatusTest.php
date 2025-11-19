<?php

use App\Models\Application;
use Mockery;

/**
 * Unit tests to verify that containers without health checks are not
 * incorrectly marked as unhealthy.
 *
 * This tests the fix for the issue where defaulting missing health status
 * to 'unhealthy' would treat containers without healthchecks as unhealthy.
 *
 * The fix removes the 'unhealthy' default and only checks health status
 * when it explicitly exists and equals 'unhealthy'.
 */
it('does not mark containers as unhealthy when health status is missing', function () {
    // Mock an application with a server
    $application = Mockery::mock(Application::class)->makePartial();
    $server = Mockery::mock('App\Models\Server')->makePartial();
    $destination = Mockery::mock('App\Models\StandaloneDocker')->makePartial();

    $destination->shouldReceive('getAttribute')
        ->with('server')
        ->andReturn($server);

    $application->shouldReceive('getAttribute')
        ->with('destination')
        ->andReturn($destination);

    $application->shouldReceive('getAttribute')
        ->with('additional_servers')
        ->andReturn(collect());

    $server->shouldReceive('getAttribute')
        ->with('id')
        ->andReturn(1);

    $server->shouldReceive('isFunctional')
        ->andReturn(true);

    // Create a container without health check (State.Health.Status is null)
    $containerWithoutHealthCheck = [
        'Config' => [
            'Labels' => [
                'com.docker.compose.service' => 'web',
            ],
        ],
        'State' => [
            'Status' => 'running',
            // Note: State.Health.Status is intentionally missing
        ],
    ];

    // Mock the remote process to return our container
    $application->shouldReceive('getAttribute')
        ->with('id')
        ->andReturn(123);

    // We can't easily test the private aggregateContainerStatuses method directly,
    // but we can verify that the code doesn't default to 'unhealthy'
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify the fix: health status should not default to 'unhealthy'
    expect($complexStatusCheckFile)
        ->not->toContain("data_get(\$container, 'State.Health.Status', 'unhealthy')")
        ->toContain("data_get(\$container, 'State.Health.Status')");

    // Verify the null check exists for non-excluded containers
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth && $containerHealth === \'unhealthy\') {');
});

it('only marks containers as unhealthy when health status explicitly equals unhealthy', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // For non-excluded containers (line ~107)
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth && $containerHealth === \'unhealthy\') {')
        ->toContain('$hasUnhealthy = true;');

    // For excluded containers (line ~141)
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth && $containerHealth === \'unhealthy\') {')
        ->toContain('$excludedHasUnhealthy = true;');
});

it('handles missing health status correctly in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify health status doesn't default to 'unhealthy'
    expect($getContainersStatusFile)
        ->not->toContain("data_get(\$container, 'State.Health.Status', 'unhealthy')")
        ->toContain("data_get(\$container, 'State.Health.Status')");

    // Verify it uses 'unknown' when health status is missing
    expect($getContainersStatusFile)
        ->toContain('$healthSuffix = $containerHealth ?? \'unknown\';');
});

it('treats containers with running status and no healthcheck as not unhealthy', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // The logic should be:
    // 1. Get health status (may be null)
    // 2. Only mark as unhealthy if health status EXISTS and equals 'unhealthy'
    // 3. Don't mark as unhealthy if health status is null/missing

    // Verify the condition requires both health to exist AND be unhealthy
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth && $containerHealth === \'unhealthy\')');

    // Verify this check is done for running containers
    expect($complexStatusCheckFile)
        ->toContain('} elseif ($containerStatus === \'running\') {')
        ->toContain('$hasRunning = true;');
});
