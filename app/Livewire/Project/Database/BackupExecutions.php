<?php

namespace App\Livewire\Project\Database;

use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class BackupExecutions extends Component
{
    public $backup;
    public $executions = [];
    public $setDeletableBackup;
    public function getListeners()
    {
        $userId = auth()->user()->id;
        return [
            "echo-private:team.{$userId},BackupCreated" => 'refreshBackupExecutions',
            "refreshBackupExecutions",
            "deleteBackup"
        ];
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
    public function download($exeuctionId)
    {
        try {
            $execution = $this->backup->executions()->where('id', $exeuctionId)->first();
            if (is_null($execution)) {
                $this->dispatch('error', 'Backup execution not found.');
                return;
            }
            $filename = data_get($execution, 'filename');
            if ($execution->scheduledDatabaseBackup->database->getMorphClass() === 'App\Models\ServiceDatabase') {
                $server = $execution->scheduledDatabaseBackup->database->service->destination->server;
            } else {
                $server = $execution->scheduledDatabaseBackup->database->destination->server;
            }
            $privateKeyLocation = savePrivateKeyToFs($server);
            $disk = Storage::build([
                'driver' => 'sftp',
                'host' => $server->ip,
                'port' => $server->port,
                'username' => $server->user,
                'privateKey' => $privateKeyLocation,
            ]);
            return $disk->download($filename);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function refreshBackupExecutions(): void
    {
        $this->executions = $this->backup->executions()->get()->sortByDesc('created_at');
    }
}
