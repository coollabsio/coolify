<?php

namespace App\Livewire\Project\Database;

use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class BackupExecutions extends Component
{
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

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupExecutions',
        ];
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
            if ($execution->filename) {
                deleteBackupsLocally($execution->filename, $server);

                if ($this->delete_backup_s3 && $execution->scheduledDatabaseBackup->s3) {
                    deleteBackupsS3($execution->filename, $execution->scheduledDatabaseBackup->s3);
                }
            }

            $execution->delete();
            $this->dispatch('success', 'Backup deleted.');
            $this->refreshBackupExecutions();
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete backup: '.$e->getMessage());
        }
    }

    public function download_file($exeuctionId)
    {
        return redirect()->route('download.backup', $exeuctionId);
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
