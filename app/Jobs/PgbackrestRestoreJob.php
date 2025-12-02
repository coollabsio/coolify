<?php

namespace App\Jobs;

use App\Actions\Database\StartPostgresql;
use App\Events\DatabaseStatusChanged;
use App\Models\StandalonePostgresql;
use App\Notifications\Database\PgbackrestRestoreFailed;
use App\Notifications\Database\PgbackrestRestoreSuccess;
use App\Services\PgbackrestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PgBackRest Restore Job
 *
 * Executes a complete restore flow:
 * 1. Pre-flight validation (before any destructive actions)
 * 2. Stop PostgreSQL container
 * 3. Clear PGDATA (automatic, no user confirmation)
 * 4. Restore from pgBackRest backup
 * 5. Start PostgreSQL container
 *
 * Safety: If pre-flight validation fails, no destructive actions are taken.
 * The restore uses the same execution context (temp container) for both
 * validation and restore to ensure consistency.
 */
class PgbackrestRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;

    public $tries = 1;

    private ?PgbackrestService $service = null;

    public function __construct(
        public StandalonePostgresql $database,
        public ?string $backupLabel = null,
        public ?string $targetTime = null,
        public bool $restartAfter = true
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $this->service = PgbackrestService::for($this->database);

        Log::info('Starting pgBackRest restore', [
            'database_id' => $this->database->id,
            'database_uuid' => $this->database->uuid,
            'backup_label' => $this->backupLabel,
            'target_time' => $this->targetTime,
            'repo_type' => $this->service->getRepoType(),
        ]);

        $this->updateRestoreStatus('running', 'Running pre-flight checks...');

        try {
            $validation = $this->service->validateRestoreDeep($this->backupLabel, $this->targetTime);

            if (! $validation['valid']) {
                Log::warning('pgBackRest restore pre-flight validation failed', [
                    'database_id' => $this->database->id,
                    'message' => $validation['message'],
                    'diagnostics' => $validation['diagnostics'] ?? [],
                ]);

                $this->database->update(['status' => 'error']);
                $this->updateRestoreStatus('failed', $validation['message']);

                $team = $this->database->team();
                $team?->notify(new PgbackrestRestoreFailed(
                    $this->database,
                    "Pre-flight check failed: {$validation['message']}",
                    $this->backupLabel
                ));

                return;
            }

            Log::info('pgBackRest pre-flight validation passed', [
                'database_id' => $this->database->id,
                'diagnostics' => $validation['diagnostics'] ?? [],
            ]);

            $this->updateRestoreStatus('running', 'Stopping PostgreSQL container...');
            $this->service->stopContainer();

            $this->updateRestoreStatus('running', 'Clearing data directory...');
            $this->service->clearDataDirectory();

            $restoreLabel = $this->backupLabel ?? 'latest';
            $this->updateRestoreStatus('running', "Restoring from backup: {$restoreLabel}...");

            $output = $this->service->restore($this->backupLabel, $this->targetTime);

            Log::info('pgBackRest restore completed', [
                'database_id' => $this->database->id,
                'output_length' => strlen($output),
            ]);

            if ($this->restartAfter) {
                $this->updateRestoreStatus('running', 'Restore complete. Starting PostgreSQL...');
                Log::info('Starting PostgreSQL after restore', ['database_id' => $this->database->id]);
                StartPostgresql::run($this->database);
            } else {
                $this->database->update(['status' => 'exited']);
            }

            $this->updateRestoreStatus('success', 'Restore completed successfully.');

            $team = $this->database->team();
            $team?->notify(new PgbackrestRestoreSuccess($this->database, $this->backupLabel));

        } catch (\Throwable $e) {
            $errorMessage = PgbackrestService::formatErrorMessage($e);

            Log::error('pgBackRest restore failed', [
                'database_id' => $this->database->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->database->update(['status' => 'error']);
            $this->updateRestoreStatus('failed', $errorMessage);

            $team = $this->database->team();
            $team?->notify(new PgbackrestRestoreFailed($this->database, $e->getMessage(), $this->backupLabel));

            throw $e;
        }
    }

    private function updateRestoreStatus(string $status, string $message): void
    {
        $dbStatus = match ($status) {
            'running' => 'restoring',
            'failed' => 'error',
            'success' => $this->restartAfter ? 'running' : 'exited',
            default => $this->database->status,
        };

        $this->database->update([
            'pgbackrest_restore_status' => $status,
            'pgbackrest_restore_message' => $message,
            'status' => $dbStatus,
        ]);

        $this->broadcastStatusChange();
    }

    private function broadcastStatusChange(): void
    {
        $team = $this->database->team();
        if ($team) {
            foreach ($team->members as $member) {
                DatabaseStatusChanged::dispatch($member->id);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('pgBackRest restore job failed', [
            'database_id' => $this->database->id,
            'error' => $exception->getMessage(),
        ]);

        $this->database->update([
            'status' => 'error',
            'pgbackrest_restore_status' => 'failed',
            'pgbackrest_restore_message' => PgbackrestService::formatErrorMessage($exception),
        ]);

        $this->broadcastStatusChange();
    }
}
