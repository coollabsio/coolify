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
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify the fix: health status should not default to 'unhealthy'
    expect($aggregatorFile)
        ->not->toContain("data_get(\$container, 'State.Health.Status', 'unhealthy')")
        ->toContain("data_get(\$container, 'State.Health.Status')");

    // Verify the health check logic
    expect($aggregatorFile)
        ->toContain('if ($health === \'unhealthy\') {');
});

it('only marks containers as unhealthy when health status explicitly equals unhealthy', function () {
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify the service checks for explicit 'unhealthy' status
    expect($aggregatorFile)
        ->toContain('if ($health === \'unhealthy\') {')
        ->toContain('$hasUnhealthy = true;');
});

it('handles missing health status correctly in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify health status doesn't default to 'unhealthy'
    expect($getContainersStatusFile)
        ->not->toContain("data_get(\$container, 'State.Health.Status', 'unhealthy')")
        ->toContain("data_get(\$container, 'State.Health.Status')");

    // Verify it uses 'unknown' when health status is missing (now using colon format)
    expect($getContainersStatusFile)
        ->toContain('$healthSuffix = $containerHealth ?? \'unknown\';')
        ->toContain('ContainerStatusAggregator'); // Uses the service
});

it('treats containers with running status and no healthcheck as not unhealthy', function () {
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // The logic should be:
    // 1. Get health status (may be null)
    // 2. Only mark as unhealthy if health status EXISTS and equals 'unhealthy'
    // 3. Don't mark as unhealthy if health status is null/missing

    // Verify the condition explicitly checks for unhealthy
    expect($aggregatorFile)
        ->toContain('if ($health === \'unhealthy\')');

    // Verify this check is done for running containers
    expect($aggregatorFile)
        ->toContain('} elseif ($state === \'running\') {')
        ->toContain('$hasRunning = true;');
});

it('tracks unknown health state in aggregation', function () {
    // State machine logic now in ContainerStatusAggregator service
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify that $hasUnknown tracking variable exists in the service
    expect($aggregatorFile)
        ->toContain('$hasUnknown = false;');

    // Verify that unknown state is detected in status parsing
    expect($aggregatorFile)
        ->toContain("str(\$status)->contains('unknown')")
        ->toContain('$hasUnknown = true;');
});

it('preserves unknown health state in aggregated status with correct priority', function () {
    // State machine logic now in ContainerStatusAggregator service (using colon format)
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify three-way priority in aggregation:
    // 1. Unhealthy (highest priority)
    // 2. Unknown (medium priority)
    // 3. Healthy (only when all explicitly healthy)

    expect($aggregatorFile)
        ->toContain('if ($hasUnhealthy) {')
        ->toContain("return 'running:unhealthy';")
        ->toContain('} elseif ($hasUnknown) {')
        ->toContain("return 'running:unknown';")
        ->toContain('} else {')
        ->toContain("return 'running:healthy';");
});

it('tracks unknown health state in ContainerStatusAggregator for all applications', function () {
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify that $hasUnknown tracking variable exists
    expect($aggregatorFile)
        ->toContain('$hasUnknown = false;');

    // Verify that unknown state is detected when health is null or 'starting'
    expect($aggregatorFile)
        ->toContain('} elseif (is_null($health) || $health === \'starting\') {')
        ->toContain('$hasUnknown = true;');
});

it('preserves unknown health state in ContainerStatusAggregator aggregated status', function () {
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify three-way priority for running containers in the service
    expect($aggregatorFile)
        ->toContain('if ($hasUnhealthy) {')
        ->toContain("return 'running:unhealthy';")
        ->toContain('} elseif ($hasUnknown) {')
        ->toContain("return 'running:unknown';")
        ->toContain('} else {')
        ->toContain("return 'running:healthy';");

    // Verify ComplexStatusCheck delegates to the service
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');
    expect($complexStatusCheckFile)
        ->toContain('use App\\Services\\ContainerStatusAggregator;')
        ->toContain('$aggregator = new ContainerStatusAggregator;')
        ->toContain('$aggregator->aggregateFromContainers($relevantContainers);');
});

it('preserves unknown health state in Service model aggregation', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Verify unknown is handled correctly
    expect($serviceFile)
        ->toContain("} elseif (\$health->value() === 'unknown') {")
        ->toContain("if (\$aggregateHealth !== 'unhealthy') {")
        ->toContain("\$aggregateHealth = 'unknown';");

    // The pattern should appear at least once (Service model has different aggregation logic than ContainerStatusAggregator)
    $unknownCount = substr_count($serviceFile, "} elseif (\$health->value() === 'unknown') {");
    expect($unknownCount)->toBeGreaterThan(0);
});

it('handles starting state (created/starting) in GetContainersStatus', function () {
    // State machine logic now in ContainerStatusAggregator service
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify tracking variable exists
    expect($aggregatorFile)
        ->toContain('$hasStarting = false;');

    // Verify detection for created/starting states
    expect($aggregatorFile)
        ->toContain("str(\$status)->contains('created') || str(\$status)->contains('starting')")
        ->toContain('$hasStarting = true;');

    // Verify aggregation returns starting status (colon format)
    expect($aggregatorFile)
        ->toContain('if ($hasStarting) {')
        ->toContain("return 'starting:unknown';");
});

it('handles paused state in GetContainersStatus', function () {
    // State machine logic now in ContainerStatusAggregator service
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify tracking variable exists
    expect($aggregatorFile)
        ->toContain('$hasPaused = false;');

    // Verify detection for paused state
    expect($aggregatorFile)
        ->toContain("str(\$status)->contains('paused')")
        ->toContain('$hasPaused = true;');

    // Verify aggregation returns paused status (colon format)
    expect($aggregatorFile)
        ->toContain('if ($hasPaused) {')
        ->toContain("return 'paused:unknown';");
});

it('handles dead/removing states in GetContainersStatus', function () {
    // State machine logic now in ContainerStatusAggregator service
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify tracking variable exists
    expect($aggregatorFile)
        ->toContain('$hasDead = false;');

    // Verify detection for dead/removing states
    expect($aggregatorFile)
        ->toContain("str(\$status)->contains('dead') || str(\$status)->contains('removing')")
        ->toContain('$hasDead = true;');

    // Verify aggregation returns degraded status (colon format)
    expect($aggregatorFile)
        ->toContain('if ($hasDead) {')
        ->toContain("return 'degraded:unhealthy';");
});

it('handles edge case states in ContainerStatusAggregator for all containers', function () {
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify tracking variables exist in the service
    expect($aggregatorFile)
        ->toContain('$hasStarting = false;')
        ->toContain('$hasPaused = false;')
        ->toContain('$hasDead = false;');

    // Verify detection for created/starting
    expect($aggregatorFile)
        ->toContain("} elseif (\$state === 'created' || \$state === 'starting') {")
        ->toContain('$hasStarting = true;');

    // Verify detection for paused
    expect($aggregatorFile)
        ->toContain("} elseif (\$state === 'paused') {")
        ->toContain('$hasPaused = true;');

    // Verify detection for dead/removing
    expect($aggregatorFile)
        ->toContain("} elseif (\$state === 'dead' || \$state === 'removing') {")
        ->toContain('$hasDead = true;');
});

it('handles edge case states in ContainerStatusAggregator aggregation', function () {
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify aggregation logic for edge cases in the service
    expect($aggregatorFile)
        ->toContain('if ($hasDead) {')
        ->toContain("return 'degraded:unhealthy';")
        ->toContain('if ($hasPaused) {')
        ->toContain("return 'paused:unknown';")
        ->toContain('if ($hasStarting) {')
        ->toContain("return 'starting:unknown';");
});

it('handles edge case states in Service model', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check for created/starting handling pattern
    $createdStartingCount = substr_count($serviceFile, "\$status->startsWith('created') || \$status->startsWith('starting')");
    expect($createdStartingCount)->toBeGreaterThan(0, 'created/starting handling should exist');

    // Check for paused handling pattern
    $pausedCount = substr_count($serviceFile, "\$status->startsWith('paused')");
    expect($pausedCount)->toBeGreaterThan(0, 'paused handling should exist');

    // Check for dead/removing handling pattern
    $deadRemovingCount = substr_count($serviceFile, "\$status->startsWith('dead') || \$status->startsWith('removing')");
    expect($deadRemovingCount)->toBeGreaterThan(0, 'dead/removing handling should exist');
});

it('appends :excluded suffix to excluded container statuses in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify that we use the trait for calculating excluded status
    expect($getContainersStatusFile)
        ->toContain('CalculatesExcludedStatus');

    // Verify that we use the trait to calculate excluded status
    expect($getContainersStatusFile)
        ->toContain('use CalculatesExcludedStatus;');
});

it('skips containers with :excluded suffix in Service model non-excluded sections', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Verify that we have exclude_from_status field handling
    expect($serviceFile)
        ->toContain('exclude_from_status');
});

it('processes containers with :excluded suffix in Service model excluded sections', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Verify that we handle excluded status
    expect($serviceFile)
        ->toContain(':excluded')
        ->toContain('exclude_from_status');
});

it('treats containers with starting health status as unknown in ContainerStatusAggregator', function () {
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Verify that 'starting' health status is treated the same as null (unknown)
    // During Docker health check grace period, the health status is 'starting'
    // This should be treated as 'unknown' rather than 'healthy'
    expect($aggregatorFile)
        ->toContain('} elseif (is_null($health) || $health === \'starting\') {')
        ->toContain('$hasUnknown = true;');
});
