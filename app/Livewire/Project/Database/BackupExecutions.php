<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\Pgbackrest\RestoreFromPgbackrest;
use App\Jobs\PgbackrestRestoreJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandalonePostgresql;
use App\Services\PgbackrestService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class BackupExecutions extends Component
{
    use AuthorizesRequests;

    public ?ScheduledDatabaseBackup $backup = null;

    public $database;

    public ?Collection $executions;

    public int $executions_count = 0;

    public int $skip = 0;

    public int $defaultTake = 10;

    public bool $showNext = false;

    public bool $showPrev = false;

    public int $currentPage = 1;

    public $setDeletableBackup;

    public $delete_backup_s3 = false;

    public $delete_backup_sftp = false;

    public $delete_pgbackrest_repo = false;

    // Restore progress tracking
    public bool $showRestoreProgress = false;

    public ?string $restoreStatus = null;

    public ?string $restoreMessage = null;

    public ?string $restoreBackupLabel = null;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupExecutions',
            "echo-private:user.{$userId},DatabaseStatusChanged" => 'checkRestoreStatus',
        ];
    }

    public function checkRestoreStatus(): void
    {
        if ($this->database instanceof StandalonePostgresql) {
            $this->database->refresh();
            $this->restoreStatus = $this->database->pgbackrest_restore_status;
            $this->restoreMessage = $this->database->pgbackrest_restore_message;

            // Show progress modal if restore is running
            if ($this->restoreStatus === 'running' || $this->database->status === 'restoring') {
                $this->showRestoreProgress = true;
            }
        }
    }

    public function dismissRestoreProgress(): void
    {
        if ($this->database instanceof StandalonePostgresql) {
            $this->database->update([
                'pgbackrest_restore_status' => null,
                'pgbackrest_restore_message' => null,
                'pgbackrest_restore_started_at' => null,
            ]);
        }

        $this->showRestoreProgress = false;
        $this->restoreStatus = null;
        $this->restoreMessage = null;
        $this->restoreBackupLabel = null;
    }

    public function pollRestoreStatus(): void
    {
        if ($this->showRestoreProgress && $this->database instanceof StandalonePostgresql) {
            $this->database->refresh();
            $this->restoreStatus = $this->database->pgbackrest_restore_status;
            $this->restoreMessage = $this->database->pgbackrest_restore_message;
        }
    }

    public function cleanupFailed()
    {
        if ($this->backup) {
            $this->backup->executions()->where('status', 'failed')->delete();
            $this->refreshBackupExecutions();
            $this->dispatch('success', 'Failed backups cleaned up.');
        }
    }

    public function cleanupDeleted()
    {
        if ($this->backup) {
            $deletedCount = $this->backup->executions()->where('local_storage_deleted', true)->count();
            if ($deletedCount > 0) {
                $this->backup->executions()->where('local_storage_deleted', true)->delete();
                $this->refreshBackupExecutions();
                $this->dispatch('success', "Cleaned up {$deletedCount} backup entries deleted from local storage.");
            } else {
                $this->dispatch('info', 'No backup entries found that are deleted from local storage.');
            }
        }
    }

    public function deleteBackup($executionId, $password)
    {
        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }

        $execution = $this->backup->executions()->where('id', $executionId)->first();
        if (is_null($execution)) {
            $this->dispatch('error', 'Backup execution not found.');

            return;
        }

        $server = $execution->scheduledDatabaseBackup->database->getMorphClass() === \App\Models\ServiceDatabase::class
            ? $execution->scheduledDatabaseBackup->database->service->destination->server
            : $execution->scheduledDatabaseBackup->database->destination->server;

        try {
            $filename = $execution->filename ?? null;
            $isPgbackrest = is_string($filename) && str_starts_with($filename, 'pgbackrest:');

            if ($filename && ! $isPgbackrest) {
                deleteBackupsLocally($filename, $server);

                if ($this->delete_backup_s3 && $execution->scheduledDatabaseBackup->s3) {
                    deleteBackupsS3($filename, $execution->scheduledDatabaseBackup->s3);
                }
            }

            if ($isPgbackrest && $this->delete_pgbackrest_repo) {
                $database = $this->database;
                if ($database instanceof StandalonePostgresql) {
                    $backupLabel = $this->findBackupLabelForExecution($database, $execution);
                    if ($backupLabel) {
                        $deleteResult = PgbackrestService::for($database)->deleteBackup($backupLabel);
                        if (! $deleteResult['success']) {
                            $this->dispatch('error', $deleteResult['message']);

                            return;
                        }
                    }
                }
            }

            $execution->delete();

            if ($isPgbackrest) {
                if ($this->delete_pgbackrest_repo) {
                    $this->dispatch('success', 'Backup deleted from pgBackRest repository and entry removed.');
                } else {
                    $this->dispatch('success', 'Backup entry removed. Note: The actual backup data is managed by pgBackRest retention policies.');
                }
            } else {
                $this->dispatch('success', 'Backup deleted.');
            }
            $this->refreshBackupExecutions();
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());
        }
    }

    public function download_file($exeuctionId)
    {
        return redirect()->route('download.backup', $exeuctionId);
    }

    public function restoreFromPgbackrest(int|string $executionId): void
    {
        $executionId = (int) trim((string) $executionId, "'\"");
        $execution = ScheduledDatabaseBackupExecution::find($executionId);

        if (! $execution) {
            $this->dispatch('error', 'Backup execution not found.');

            return;
        }

        $database = $this->database;

        if (! $database instanceof StandalonePostgresql) {
            $this->dispatch('error', 'pgBackRest restore is only available for PostgreSQL databases.');

            return;
        }

        $this->authorize('update', $database);

        if (! $database->isPgbackrestEnabled()) {
            $this->dispatch('error', 'pgBackRest is not enabled for this database.');

            return;
        }

        $filename = $execution->filename;
        if (! str_starts_with($filename, 'pgbackrest:')) {
            $this->dispatch('error', 'This is not a pgBackRest backup.');

            return;
        }

        $backupLabel = $execution->pgbackrest_label;

        if (empty($backupLabel)) {
            $this->dispatch('error', 'This backup does not have a pgBackRest label stored. It may be from an older version.');

            return;
        }

        $restoreAction = new RestoreFromPgbackrest;
        $validation = $restoreAction->validateRestore($database, $backupLabel);

        if (! $validation['valid']) {
            $this->dispatch('error', $validation['message']);

            return;
        }

        $this->restoreBackupLabel = $backupLabel;
        $this->restoreStatus = 'running';
        $this->restoreMessage = "Starting restore from backup: {$backupLabel}";
        $this->showRestoreProgress = true;

        PgbackrestRestoreJob::dispatch($database, $backupLabel, null, true);
        $this->dispatch('success', 'Restore job started. Please wait...');
    }

    private function findBackupLabelForExecution(StandalonePostgresql $database, ScheduledDatabaseBackupExecution $execution): ?string
    {
        if (empty($execution->pgbackrest_label)) {
            return null;
        }

        $backups = PgbackrestService::for($database)->getBackupList();
        $exists = $backups->contains('label', $execution->pgbackrest_label);

        return $exists ? $execution->pgbackrest_label : null;
    }

    public function isPgbackrestBackupDeletableFromRepo(int $executionId): array
    {
        $execution = ScheduledDatabaseBackupExecution::find($executionId);
        if (! $execution) {
            return ['deletable' => false, 'reason' => 'Execution not found'];
        }

        $filename = $execution->filename ?? '';
        if (! str_starts_with($filename, 'pgbackrest:')) {
            return ['deletable' => false, 'reason' => 'Not a pgBackRest backup'];
        }

        $database = $this->database;
        if (! $database instanceof StandalonePostgresql) {
            return ['deletable' => false, 'reason' => 'Not a PostgreSQL database'];
        }

        $backupLabel = $this->findBackupLabelForExecution($database, $execution);
        if (! $backupLabel) {
            return ['deletable' => false, 'reason' => 'Backup not found in repository'];
        }

        return PgbackrestService::for($database)->isBackupDeletable($backupLabel);
    }

    public function refreshBackupExecutions(): void
    {
        $this->loadExecutions();
    }

    public function reloadExecutions()
    {
        $this->loadExecutions();
    }

    public function previousPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip - $take;
        }
        $this->skip = $this->skip - $this->defaultTake;
        if ($this->skip < 0) {
            $this->showPrev = false;
            $this->skip = 0;
        }
        $this->updateCurrentPage();
        $this->loadExecutions();
    }

    public function nextPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip + $take;
        }
        $this->showPrev = true;
        $this->updateCurrentPage();
        $this->loadExecutions();
    }

    private function loadExecutions()
    {
        if ($this->backup && $this->backup->exists) {
            ['executions' => $executions, 'count' => $count] = $this->backup->executionsPaginated($this->skip, $this->defaultTake);
            $this->executions = $executions;
            $this->executions_count = $count;
        } else {
            $this->executions = collect([]);
            $this->executions_count = 0;
        }
        $this->showMore();
    }

    private function showMore()
    {
        if ($this->executions->count() !== 0) {
            $this->showNext = true;
            if ($this->executions->count() < $this->defaultTake) {
                $this->showNext = false;
            }

            return;
        }
    }

    private function updateCurrentPage()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
    }

    public function mount(ScheduledDatabaseBackup $backup)
    {
        $this->backup = $backup;
        $this->database = $backup->database;
        $this->updateCurrentPage();
        $this->loadExecutions();

        if ($this->database instanceof StandalonePostgresql) {
            $this->restoreStatus = $this->database->pgbackrest_restore_status;
            $this->restoreMessage = $this->database->pgbackrest_restore_message;
            if ($this->restoreStatus === 'running' || $this->database->status === 'restoring') {
                $this->showRestoreProgress = true;
            }
        }
    }

    public function server()
    {
        if ($this->database) {
            $server = null;

            if ($this->database instanceof \App\Models\ServiceDatabase) {
                $server = $this->database->service->destination->server;
            } elseif ($this->database->destination && $this->database->destination->server) {
                $server = $this->database->destination->server;
            }
            if ($server) {
                return $server;
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.project.database.backup-executions');
    }
}
