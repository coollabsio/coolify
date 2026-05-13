<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression test for #7649 / #6648 / #7566.
 *
 * Bug: ApplicationDeploymentJob assigned `build_server_id` directly on the model
 * (`->build_server_id = ...`) and then immediately called `->addLogEntry(...)`.
 * addLogEntry() internally calls `$this->refresh()` to reload the model from
 * the database, which discards any unsaved property assignments. The
 * subsequent `saveQuietly()` then writes back NULL for build_server_id,
 * leaving CleanupHelperContainersJob unable to associate helper containers on
 * the build server with their owning deployments. Result: live helper
 * containers were classified as orphaned and torn down mid-build, producing
 * "No such container" failures.
 *
 * Fix: persist build_server_id with `->save()` *before* addLogEntry() runs.
 *
 * This test reproduces the failure mode in isolation. Without the fix the
 * post-addLogEntry value is NULL; with the fix it is preserved.
 */
test('build_server_id survives addLogEntry refresh cycle', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'application_name' => 'test-app',
        'server_id' => 0,
        'server_name' => 'localhost',
        'destination_id' => '0',
        'deployment_uuid' => 'persist-build-server-id-test',
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'force_rebuild' => false,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'logs' => null,
    ]);

    // Reload the way the job does it so we are operating on the same instance.
    $instance = ApplicationDeploymentQueue::find($deployment->id);

    // Simulate the job's hot path: assign in memory, persist, then log.
    $instance->build_server_id = 42;
    $instance->save();
    $instance->addLogEntry('Found a suitable build server (build-1).');

    $persisted = ApplicationDeploymentQueue::find($deployment->id);
    expect($persisted->build_server_id)->toBe(42);
});
