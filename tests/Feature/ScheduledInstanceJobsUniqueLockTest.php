<?php

use App\Jobs\CheckMissingDatabaseBackupsJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\CleanupOrphanedPreviewContainersJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('cache.default', 'array');
    Queue::fake();
});

dataset('scheduled instance jobs', [
    'orphaned preview containers cleanup' => [CleanupOrphanedPreviewContainersJob::class, 600],
    'instance cleanup' => [CleanupInstanceStuffsJob::class, 60],
    'missing database backups check' => [CheckMissingDatabaseBackupsJob::class, 1800],
]);

it('does not queue the job again while its unique lock is held', function (string $jobClass, int $lockSeconds) {
    $jobClass::dispatch();
    $this->travel($lockSeconds - 1)->seconds();
    $jobClass::dispatch();

    Queue::assertPushed($jobClass, 1);
})->with('scheduled instance jobs');

it('queues the job again after a unique lock left by a killed worker expires', function (string $jobClass, int $lockSeconds) {
    $jobClass::dispatch();
    $this->travel($lockSeconds + 1)->seconds();
    $jobClass::dispatch();

    Queue::assertPushed($jobClass, 2);
})->with('scheduled instance jobs');
