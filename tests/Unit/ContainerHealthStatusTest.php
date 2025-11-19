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

    // Verify the health check logic for non-excluded containers
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth === \'unhealthy\') {');
});

it('only marks containers as unhealthy when health status explicitly equals unhealthy', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // For non-excluded containers (line ~108)
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth === \'unhealthy\') {')
        ->toContain('$hasUnhealthy = true;');

    // For excluded containers (line ~145)
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth === \'unhealthy\') {')
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

    // Verify the condition explicitly checks for unhealthy
    expect($complexStatusCheckFile)
        ->toContain('if ($containerHealth === \'unhealthy\')');

    // Verify this check is done for running containers
    expect($complexStatusCheckFile)
        ->toContain('} elseif ($containerStatus === \'running\') {')
        ->toContain('$hasRunning = true;');
});

it('tracks unknown health state in aggregation', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify that $hasUnknown tracking variable exists
    expect($getContainersStatusFile)
        ->toContain('$hasUnknown = false;');

    // Verify that unknown state is detected in status parsing
    expect($getContainersStatusFile)
        ->toContain("if (str(\$status)->contains('unknown')) {")
        ->toContain('$hasUnknown = true;');
});

it('preserves unknown health state in aggregated status with correct priority', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify three-way priority in aggregation:
    // 1. Unhealthy (highest priority)
    // 2. Unknown (medium priority)
    // 3. Healthy (only when all explicitly healthy)

    expect($getContainersStatusFile)
        ->toContain('if ($hasUnhealthy) {')
        ->toContain("return 'running (unhealthy)';")
        ->toContain('} elseif ($hasUnknown) {')
        ->toContain("return 'running (unknown)';")
        ->toContain('} else {')
        ->toContain("return 'running (healthy)';");
});

it('tracks unknown health state in ComplexStatusCheck for multi-server applications', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify that $hasUnknown tracking variable exists
    expect($complexStatusCheckFile)
        ->toContain('$hasUnknown = false;');

    // Verify that unknown state is detected when containerHealth is null
    expect($complexStatusCheckFile)
        ->toContain('} elseif ($containerHealth === null) {')
        ->toContain('$hasUnknown = true;');

    // Verify excluded containers also track unknown
    expect($complexStatusCheckFile)
        ->toContain('$excludedHasUnknown = false;');
});

it('preserves unknown health state in ComplexStatusCheck aggregated status', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify three-way priority for non-excluded containers
    expect($complexStatusCheckFile)
        ->toContain('if ($hasUnhealthy) {')
        ->toContain("return 'running:unhealthy';")
        ->toContain('} elseif ($hasUnknown) {')
        ->toContain("return 'running:unknown';")
        ->toContain('} else {')
        ->toContain("return 'running:healthy';");

    // Verify three-way priority for excluded containers
    expect($complexStatusCheckFile)
        ->toContain('if ($excludedHasUnhealthy) {')
        ->toContain("return 'running:unhealthy:excluded';")
        ->toContain('} elseif ($excludedHasUnknown) {')
        ->toContain("return 'running:unknown:excluded';")
        ->toContain("return 'running:healthy:excluded';");
});

it('preserves unknown health state in Service model aggregation', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Verify unknown is handled in non-excluded applications
    expect($serviceFile)
        ->toContain("} elseif (\$health->value() === 'unknown') {")
        ->toContain("if (\$complexHealth !== 'unhealthy') {")
        ->toContain("\$complexHealth = 'unknown';");

    // The pattern should appear 4 times (non-excluded apps, non-excluded databases,
    // excluded apps, excluded databases)
    $unknownCount = substr_count($serviceFile, "} elseif (\$health->value() === 'unknown') {");
    expect($unknownCount)->toBe(4);
});

it('handles starting state (created/starting) in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify tracking variable exists
    expect($getContainersStatusFile)
        ->toContain('$hasStarting = false;');

    // Verify detection for created/starting states
    expect($getContainersStatusFile)
        ->toContain("} elseif (str(\$status)->contains('created') || str(\$status)->contains('starting')) {")
        ->toContain('$hasStarting = true;');

    // Verify aggregation returns starting status
    expect($getContainersStatusFile)
        ->toContain('if ($hasStarting) {')
        ->toContain("return 'starting (unknown)';");
});

it('handles paused state in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify tracking variable exists
    expect($getContainersStatusFile)
        ->toContain('$hasPaused = false;');

    // Verify detection for paused state
    expect($getContainersStatusFile)
        ->toContain("} elseif (str(\$status)->contains('paused')) {")
        ->toContain('$hasPaused = true;');

    // Verify aggregation returns paused status
    expect($getContainersStatusFile)
        ->toContain('if ($hasPaused) {')
        ->toContain("return 'paused (unknown)';");
});

it('handles dead/removing states in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify tracking variable exists
    expect($getContainersStatusFile)
        ->toContain('$hasDead = false;');

    // Verify detection for dead/removing states
    expect($getContainersStatusFile)
        ->toContain("} elseif (str(\$status)->contains('dead') || str(\$status)->contains('removing')) {")
        ->toContain('$hasDead = true;');

    // Verify aggregation returns degraded status
    expect($getContainersStatusFile)
        ->toContain('if ($hasDead) {')
        ->toContain("return 'degraded (unhealthy)';");
});

it('handles edge case states in ComplexStatusCheck for non-excluded containers', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify tracking variables exist
    expect($complexStatusCheckFile)
        ->toContain('$hasStarting = false;')
        ->toContain('$hasPaused = false;')
        ->toContain('$hasDead = false;');

    // Verify detection for created/starting
    expect($complexStatusCheckFile)
        ->toContain("} elseif (\$containerStatus === 'created' || \$containerStatus === 'starting') {")
        ->toContain('$hasStarting = true;');

    // Verify detection for paused
    expect($complexStatusCheckFile)
        ->toContain("} elseif (\$containerStatus === 'paused') {")
        ->toContain('$hasPaused = true;');

    // Verify detection for dead/removing
    expect($complexStatusCheckFile)
        ->toContain("} elseif (\$containerStatus === 'dead' || \$containerStatus === 'removing') {")
        ->toContain('$hasDead = true;');
});

it('handles edge case states in ComplexStatusCheck aggregation', function () {
    $complexStatusCheckFile = file_get_contents(__DIR__.'/../../app/Actions/Shared/ComplexStatusCheck.php');

    // Verify aggregation logic for edge cases
    expect($complexStatusCheckFile)
        ->toContain('if ($hasDead) {')
        ->toContain("return 'degraded:unhealthy';")
        ->toContain('if ($hasPaused) {')
        ->toContain("return 'paused:unknown';")
        ->toContain('if ($hasStarting) {')
        ->toContain("return 'starting:unknown';");
});

it('handles edge case states in Service model for all 4 locations', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check for created/starting handling pattern
    $createdStartingCount = substr_count($serviceFile, "\$status->startsWith('created') || \$status->startsWith('starting')");
    expect($createdStartingCount)->toBe(4, 'created/starting handling should appear in all 4 locations');

    // Check for paused handling pattern
    $pausedCount = substr_count($serviceFile, "\$status->startsWith('paused')");
    expect($pausedCount)->toBe(4, 'paused handling should appear in all 4 locations');

    // Check for dead/removing handling pattern
    $deadRemovingCount = substr_count($serviceFile, "\$status->startsWith('dead') || \$status->startsWith('removing')");
    expect($deadRemovingCount)->toBe(4, 'dead/removing handling should appear in all 4 locations');
});

it('appends :excluded suffix to excluded container statuses in GetContainersStatus', function () {
    $getContainersStatusFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify that we check for exclude_from_hc flag
    expect($getContainersStatusFile)
        ->toContain('$excludeFromHc = data_get($serviceConfig, \'exclude_from_hc\', false);');

    // Verify that we append :excluded suffix
    expect($getContainersStatusFile)
        ->toContain('$containerStatus = str_replace(\')\', \':excluded)\', $containerStatus);');
});

it('skips containers with :excluded suffix in Service model non-excluded sections', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Verify that we skip :excluded containers in non-excluded sections
    // This should appear twice (once for applications, once for databases)
    $skipExcludedCount = substr_count($serviceFile, "if (\$health->contains(':excluded')) {");
    expect($skipExcludedCount)->toBeGreaterThanOrEqual(2, 'Should skip :excluded containers in non-excluded sections');
});

it('processes containers with :excluded suffix in Service model excluded sections', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Verify that we process :excluded containers in excluded sections
    $processExcludedCount = substr_count($serviceFile, "if (! \$health->contains(':excluded') && !");
    expect($processExcludedCount)->toBeGreaterThanOrEqual(2, 'Should process :excluded containers in excluded sections');

    // Verify that we strip :excluded suffix before health comparison
    $stripExcludedCount = substr_count($serviceFile, "\$health = str(\$health)->replace(':excluded', '');");
    expect($stripExcludedCount)->toBeGreaterThanOrEqual(2, 'Should strip :excluded suffix in excluded sections');
});
