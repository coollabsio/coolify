<?php

namespace App\Jobs;

use App\Actions\Node\BackupNodeVolume;
use App\Actions\Node\RestoreNodeDatabase;
use App\Models\NodeWorkload;
use App\Models\ScheduledDatabaseBackupExecution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/** Restores a dump into a cluster database (decision 0010) and records the outcome for the backups page. */
class RestoreNodeDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $workloadId, public int $executionId)
    {
        $timeout = (int) (ScheduledDatabaseBackupExecution::query()->find($executionId)?->scheduledDatabaseBackup?->timeout ?? 3600);
        $this->timeout = $timeout + 120;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('node-database-restore-'.$this->workloadId))->dontRelease()->expireAfter($this->timeout)];
    }

    public function handle(): void
    {
        $workload = NodeWorkload::query()->find($this->workloadId);
        $execution = ScheduledDatabaseBackupExecution::query()->find($this->executionId);
        if ($workload === null || $execution === null) {
            return;
        }
        try {
            RestoreNodeDatabase::run($workload, $execution, now()->addSeconds($this->timeout - 120));
            RestoreNodeDatabase::record($workload, ['state' => 'succeeded', 'error' => '']);
        } catch (Throwable $exception) {
            RestoreNodeDatabase::record($workload, ['state' => 'failed', 'error' => BackupNodeVolume::redactUrls($exception->getMessage())]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $workload = NodeWorkload::query()->find($this->workloadId);
        if ($workload !== null) {
            RestoreNodeDatabase::record($workload, ['state' => 'failed', 'error' => BackupNodeVolume::redactUrls($exception?->getMessage() ?? 'The restore worker stopped.')]);
        }
    }
}
