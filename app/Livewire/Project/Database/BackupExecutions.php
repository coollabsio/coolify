<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class BackupExecutions extends Component
{
    public ?ScheduledDatabaseBackup $backup = null;
    public $database;
    public $executions = [];
    public $setDeletableBackup;

    public function getListeners()
    {
        $userId = auth()->user()->id;

        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupExecutions',
            'deleteBackup',
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

    public function deleteBackup($exeuctionId)
    {
        $execution = $this->backup->executions()->where('id', $exeuctionId)->first();
        if (is_null($execution)) {
            $this->dispatch('error', 'Backup execution not found.');

            return;
        }
        if ($execution->scheduledDatabaseBackup->database->getMorphClass() === 'App\Models\ServiceDatabase') {
            delete_backup_locally($execution->filename, $execution->scheduledDatabaseBackup->database->service->destination->server);
        } else {
            delete_backup_locally($execution->filename, $execution->scheduledDatabaseBackup->database->destination->server);
        }
        $execution->delete();
        $this->dispatch('success', 'Backup deleted.');
        $this->refreshBackupExecutions();
    }

    public function download_file($exeuctionId)
    {
        return redirect()->route('download.backup', $exeuctionId);
    }

    public function refreshBackupExecutions(): void
    {
        if ($this->backup) {
            $this->executions = $this->backup->executions()->get();
        }
    }

    public function mount(ScheduledDatabaseBackup $backup)
    {
        $this->backup = $backup;
        $this->database = $backup->database;
        $this->refreshBackupExecutions();
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

    public function getServerTimezone()
    {
        $server = $this->server();
        if (!$server) {
            return 'UTC';
        }
        $serverTimezone = $server->settings->server_timezone;
        return $serverTimezone;
    }

    public function formatDateInServerTimezone($date)
    {
        $serverTimezone = $this->getServerTimezone();
        $dateObj = new \DateTime($date);
        try {
            $dateObj->setTimezone(new \DateTimeZone($serverTimezone));
        } catch (\Exception $e) {
            $dateObj->setTimezone(new \DateTimeZone('UTC'));
        }
        return $dateObj->format('Y-m-d H:i:s T');
    }
}
