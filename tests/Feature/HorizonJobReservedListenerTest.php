<?php

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Horizon\Events\JobReserved;
use Laravel\Horizon\Listeners\MarkJobAsReserved;
use Laravel\Horizon\Listeners\StartTimingJob;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Horizon's own JobReserved listeners need Redis.
    $this->mock(MarkJobAsReserved::class)->shouldIgnoreMissing();
    $this->mock(StartTimingJob::class)->shouldIgnoreMissing();
});

function reserveDeploymentJob(int $deploymentQueueId, string $horizonJobId): void
{
    event(new JobReserved(json_encode([
        'id' => $horizonJobId,
        'displayName' => 'App\Jobs\ApplicationDeploymentJob',
        'tags' => ['App\Models\ApplicationDeploymentQueue:'.$deploymentQueueId],
    ])));
}

test('stores the Horizon job id on the reserved deployment', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'deployment_uuid' => 'reserved-uuid',
    ]);

    reserveDeploymentJob($deployment->id, 'horizon-job-1');

    expect($deployment->fresh()->horizon_job_id)->toBe('horizon-job-1');
});

test('does not crash the worker when the deployment was deleted', function () {
    reserveDeploymentJob(999999, 'horizon-job-2');

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});
