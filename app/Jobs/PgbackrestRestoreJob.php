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
            'backup_label' => $this->backupLabel,
            'target_time' => $this->targetTime,
        ]);

        $this->updateRestoreStatus('running', 'Preparing for restore...');

        try {
            $this->updateRestoreStatus('running', 'Stopping PostgreSQL container...');
            $this->service->stopContainer();

            $this->updateRestoreStatus('running', 'Clearing data directory...');
            $this->service->clearDataDirectory();

            $restoreLabel = $this->backupLabel ?? 'latest';
            $this->updateRestoreStatus('running', "Restoring from backup: {$restoreLabel}...");

            $output = $this->service->restore($this->backupLabel, $this->targetTime);

            Log::info('pgBackRest restore completed', [
                'database_id' => $this->database->id,
                'output' => $output,
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
        $this->database->update([
            'pgbackrest_restore_status' => $status,
            'pgbackrest_restore_message' => $message,
            'status' => $status === 'running' ? 'restoring' : ($status === 'failed' ? 'error' : $this->database->status),
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
