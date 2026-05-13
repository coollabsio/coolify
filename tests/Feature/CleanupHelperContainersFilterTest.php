<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression test for #7649 / #6648 / #7566.
 *
 * Bug: CleanupHelperContainersJob looked up active deployments via
 *   ApplicationDeploymentQueue::where('server_id', $this->server->id)
 *
 * When a deployment uses a dedicated build server, `server_id` holds the
 * deployment (runtime) server while the helper containers live on the build
 * server. The original filter therefore matched zero rows on the build
 * server's cleanup pass and tore down every running helper container.
 *
 * Fix: the filter must also accept rows where `build_server_id` equals the
 * server currently being cleaned up.
 */
test('cleanup filter matches deployments by either server_id or build_server_id', function () {
    $buildServerId = 99;
    $deploymentServerId = 0;

    // A deployment that runs on $deploymentServerId but builds on $buildServerId
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'application_name' => 'test-app',
        'server_id' => $deploymentServerId,
        'server_name' => 'localhost',
        'destination_id' => '0',
        'deployment_uuid' => 'cleanup-filter-test-1',
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'force_rebuild' => false,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'build_server_id' => $buildServerId,
    ]);

    // Replicate the patched query verbatim.
    $matches = ApplicationDeploymentQueue::where(function ($q) use ($buildServerId) {
        $q->where('server_id', $buildServerId)
            ->orWhere('build_server_id', $buildServerId);
    })
        ->whereIn('status', [
            ApplicationDeploymentStatus::IN_PROGRESS->value,
            ApplicationDeploymentStatus::QUEUED->value,
        ])
        ->pluck('deployment_uuid')
        ->toArray();

    expect($matches)->toContain('cleanup-filter-test-1');
});

test('cleanup filter ignores finished deployments', function () {
    $buildServerId = 99;

    ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'application_name' => 'test-app',
        'server_id' => 0,
        'server_name' => 'localhost',
        'destination_id' => '0',
        'deployment_uuid' => 'cleanup-filter-test-finished',
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'force_rebuild' => false,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'build_server_id' => $buildServerId,
    ]);

    $matches = ApplicationDeploymentQueue::where(function ($q) use ($buildServerId) {
        $q->where('server_id', $buildServerId)
            ->orWhere('build_server_id', $buildServerId);
    })
        ->whereIn('status', [
            ApplicationDeploymentStatus::IN_PROGRESS->value,
            ApplicationDeploymentStatus::QUEUED->value,
        ])
        ->pluck('deployment_uuid')
        ->toArray();

    expect($matches)->not->toContain('cleanup-filter-test-finished');
});
