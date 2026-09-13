<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Abstract Base Job for all Database Backups.
 * 
 * This class handles the lifecycle, error recovery, container cleanup, 
 * and database state reconciliation. Specific database implementations
 * must implement the runBackupProcess method.
 */
abstract class DatabaseBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The team associated with this backup. 
     * Resolved during handle() to ensure fresh state.
     */
    public ?Team $team = null;

    /**
     * These properties are intended to be mutated by the child class 
     * implementation of runBackupProcess().
     */
    public ?string $backup_location = null;
    public ?string $backup_output = null;
    public int $size = 0;

    public function __construct(
        public ScheduledDatabaseBackup $backup,
        public ?ScheduledDatabaseBackupExecution $backup_log = null,
        public ?string $backup_log_uuid = null,
        public ?Server $server = null,
    ) {}

    /**
     * The core logic to be implemented by child classes.
     * 
     * @param string $containerName The name of the helper docker container.
     * @param int $timeout Timeout in seconds.
     * @throws Throwable
     */
    abstract protected function runBackupProcess(string $containerName, int $timeout): void;

    /**
     * The Orchestrator.
     */
    public function handle(): void
    {
        $containerName = "coolify-helper-backup-{$this->backup->uuid}";

        try {
            // 1. Resolve Team Context
            $this->team = Team::find($this->backup->team_id);
            if (!$this->team) {
                $this->backup->delete();
                return;
            }

            // 2. Execute Specific Backup Logic
            $timeout = $this->backup->timeout ?? config('coolify.ssh.timeout', 3600);
            $this->runBackupProcess($containerName, $timeout);

        } catch (Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        } finally {
            // 3. GUARANTEE: Infrastructure Cleanup
            $this->cleanupContainer($containerName);
            
            // 4. GUARANTEE: Database State Reconciliation
            $this->finalizeExecution();
        }
    }

    /**
     * Handles explicit exceptions caught during the try block.
     */
    private function handleFailure(Throwable $e): void
    {
        if ($this->backup_log) {
            $this->backup_log->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensures the Docker container is removed to prevent disk leakage.
     */
    private function cleanupContainer(string $containerName): void
    {
        if (!$this->server) {
            return;
        }

        try {
            instant_remote_process(
                command: "docker rm -f {$containerName}",
                server: $this->server,
                throwError: false
            );
        } catch (Throwable $ignored) {
            // Ignore cleanup errors to avoid masking the actual backup result
        }
    }

    /**
     * Dispatches events and reconciles the execution logs.
     */
    private function finalizeExecution(): void
    {
        if ($this->team) {
            BackupCreated::dispatch($this->team->id);
        }

        if ($this->backup_log) {
            $this->reconcileLogStatus();
        } elseif ($this->backup_log_uuid) {
            $this->reconcileOrphanedLog();
        }
    }

    /**
     * Handles "Ghost" processes. If the status is still 'running',
     * we determine success/failure based on the actual data captured.
     */
    private function reconcileLogStatus(): void
    {
        $updateData = ['finished_at' => Carbon::now()->toImmutable()];

        if ($this->backup_log->status === 'running') {
            if ($this->backup_location && $this->size > 0) {
                $updateData['status'] = 'success';
                $updateData['size'] = $this->size;
                $updateData['message'] = $this->backup_output ?? 'Backup completed successfully';
            } else {
                $updateData['status'] = 'failed';
                $updateData['message'] = 'Backup execution ended unexpectedly (Timeout or Process Crash).';
            }
        }

        $this->backup_log->update($updateData);
    }

    /**
     * Fallback for when the SerializesModels trait fails to re-attach the model.
     */
    private function reconcileOrphanedLog(): void
    {
        $log = ScheduledDatabaseBackupExecution::where('uuid', $this->backup_log_uuid)->first();

        if ($log && $log->status === 'running') {
            $log->update([
                'status' => 'failed',
                'message' => 'Backup execution finished but model reference was lost during queue processing.',
                'finished_at' => Carbon::now()->toImmutable(),
            ]);
        }
    }
}
