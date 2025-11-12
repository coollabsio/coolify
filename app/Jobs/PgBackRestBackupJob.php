<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandalonePostgresql;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use App\Services\PgBackRestService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for running pgbackrest backup operation
*/

class PgBackRestBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout
    public $tries = 1;

    protected ScheduledDatabaseBackup $backup;
    protected ?ScheduledDatabaseBackupExecution $execution = null;

    public function __construct(ScheduledDatabaseBackup $backup)
    {
        $this->backup = $backup;
    }

    public function handle(): void
    {
        try {
            // Create execution record
            $this->execution = ScheduledDatabaseBackupExecution::create([
                'scheduled_database_backup_id' => $this->backup->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            Log::info('Starting pgBackRest backup job', [
                'backup_id' => $this->backup->id,
                'execution_id' => $this->execution->id,
                'database' => $this->backup->database->name,
            ]);

            // Get the database
            $database = $this->backup->database;
            
            if (!$database instanceof StandalonePostgresql) {
                throw new \Exception('pgBackRest only supports PostgreSQL databases');
            }

            // Initialize pgBackRest service
            $pgBackRest = new PgBackRestService($database);

            // Ensure pgBackRest is installed and configured
            if (!$pgBackRest->isConfigured()) {
                Log::info('pgBackRest not configured, initializing...');
                
                $installResult = $pgBackRest->install();
                if (!$installResult['success']) {
                    throw new \Exception('Failed to install pgBackRest: ' . $installResult['message']);
                }

                $s3Config = $this->getS3Config();
                $configResult = $pgBackRest->configure($s3Config);
                if (!$configResult['success']) {
                    throw new \Exception('Failed to configure pgBackRest: ' . $configResult['message']);
                }

                // First backup must be full
                $backupType = 'full';
            } else {
                // Determine backup type based on schedule or last backup
                $backupType = $this->determineBackupType();
            }

            // Perform the backup
            $backupResult = $pgBackRest->backup($backupType);

            if (!$backupResult['success']) {
                throw new \Exception('Backup failed: ' . $backupResult['message']);
            }

            // Update execution with results
            $backupInfo = $backupResult['backup'];
            $this->execution->update([
                'status' => 'success',
                'finished_at' => now(),
                'size' => $backupInfo['backup_size'] ?? 0,
                'database_size' => $backupInfo['database_size'] ?? 0,
                'message' => "Backup completed successfully (type: {$backupType})",
                'filename' => $backupInfo['label'] ?? null,
                'location' => $this->backup->save_s3 ? 's3' : 'local',
            ]);

            // Handle retention cleanup
            if ($this->backup->number_of_backups_locally > 0 || $this->backup->backup_retention_days > 0) {
                $this->removeOldBackups();
            }

            // Send success notification
            $this->backup->database->team->notify(new BackupSuccess($this->backup, $database, $database -> name));

            Log::info('pgBackRest backup job completed successfully', [
                'execution_id' => $this->execution->id,
                'type' => $backupType,
                'size' => $backupInfo['backup_size'] ?? 0,
                'duration' => $backupResult['duration'] ?? 0,
            ]);

        } catch (\Throwable $e) {
            Log::error('pgBackRest backup job failed', [
                'backup_id' => $this->backup->id,
                'execution_id' => $this->execution?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($this->execution) {
                $this->execution->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'message' => 'Backup failed: ' . $e->getMessage(),
                ]);

                // Send failure notification
                $this->backup->database->team->notify(new BackupFailed($this->backup, $this->execution, $e ->getMessage(), $this->backup->database->name));
            }

            throw $e;
        }
    }

    /**
     * Determine the backup type to perform
     */
    protected function determineBackupType(): string
    {
        // Get the last successful backup
        $lastBackup = $this->backup->executions()
            ->where('status', 'success')
            ->latest('finished_at')
            ->first();

        if (!$lastBackup) {
            return 'full';
        }

        // Check if we should do a full backup based on time
        $daysSinceLastFull = Carbon::parse($lastBackup->finished_at)->diffInDays(now());
        
        // Do a full backup weekly
        if ($daysSinceLastFull >= 7) {
            return 'full';
        }

        // Do a differential backup daily
        $hoursSinceLastBackup = Carbon::parse($lastBackup->finished_at)->diffInHours(now());
        if ($hoursSinceLastBackup >= 24) {
            return 'diff';
        }

        // Otherwise do incremental
        return 'incr';
    }

    /**
     * Get S3 configuration if enabled
     */
    protected function getS3Config(): ?array
    {
        if (!$this->backup->save_s3) {
            return null;
        }

        $s3 = $this->backup->s3_storage;
        if (!$s3) {
            return null;
        }

        return [
            'bucket' => $s3->bucket,
            'endpoint' => $s3->endpoint,
            'region' => $s3->region ?? 'us-east-1',
            'key' => $s3->key,
            'secret' => $s3->secret,
        ];
    }

    /**
     * Remove old backups according to retention policy
     */
    protected function removeOldBackups(): void
    {
        try {
            // Get all successful executions
            $executions = $this->backup->executions()
                ->where('status', 'success')
                ->orderBy('finished_at', 'desc')
                ->get();

            $toDelete = [];

            // Apply count-based retention
            if ($this->backup->number_of_backups_locally > 0) {
                $excessCount = $executions->count() - $this->backup->number_of_backups_locally;
                if ($excessCount > 0) {
                    $toDelete = array_merge(
                        $toDelete,
                        $executions->slice($this->backup->number_of_backups_locally)->pluck('id')->toArray()
                    );
                }
            }

            // Apply time-based retention
            if ($this->backup->backup_retention_days > 0) {
                $cutoffDate = now()->subDays($this->backup->backup_retention_days);
                $oldBackups = $executions->filter(function ($execution) use ($cutoffDate) {
                    return Carbon::parse($execution->finished_at)->lt($cutoffDate);
                });
                $toDelete = array_merge($toDelete, $oldBackups->pluck('id')->toArray());
            }

            // Remove duplicates
            $toDelete = array_unique($toDelete);

            if (empty($toDelete)) {
                return;
            }

            Log::info('Removing old backups', [
                'backup_id' => $this->backup->id,
                'count' => count($toDelete),
            ]);

            // Delete using pgBackRest expire command
            $database = $this->backup->database;
            $pgBackRest = new PgBackRestService($database);
            $containerName = $database->uuid;
            $stanzaName = $pgBackRest->getStanzaName();

            // pgBackRest handles retention automatically based on config
            // Just delete the execution records
            foreach ($toDelete as $executionId) {
                ScheduledDatabaseBackupExecution::find($executionId)?->delete();
            }

        } catch (\Exception $e) {
            Log::error('Failed to remove old backups', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - backup succeeded even if cleanup failed
        }
    }

    /**
     * Get the tags for the job
     */
    public function tags(): array
    {
        return [
            'backup',
            'pgbackrest',
            'database:' . $this->backup->database->uuid,
            'backup_id:' . $this->backup->id,
        ];
    }
}