<?php

namespace App\Livewire\Project\Database;

use App\Jobs\DatabaseBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ScheduledBackups extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public $database;

    public $parameters;

    public string $custom_type = 'mysql';

    public bool $scheduleModalOpen = false;

    public ?ScheduledDatabaseBackup $selectedBackup = null;

    public function getListeners(): array
    {
        return [
            'refreshScheduledBackups',
            'modalClosed' => 'closeScheduleModal',
            ...$this->teamChannelListeners(['BackupCreated' => '$refresh']),
        ];
    }

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $scheduleUuid = request()->query('schedule');
        if (is_string($scheduleUuid) && $this->database->scheduledBackups()->where('uuid', $scheduleUuid)->exists()) {
            $this->openSchedule($scheduleUuid);
        }
    }

    public function openSchedule(string $backupUuid): void
    {
        $this->authorize('view', $this->database);
        $this->selectedBackup = $this->database->scheduledBackups()->where('uuid', $backupUuid)->firstOrFail();
        $this->scheduleModalOpen = true;
    }

    public function closeScheduleModal(): void
    {
        $this->scheduleModalOpen = false;
        $this->selectedBackup = null;
        $this->database->refresh();
        // A link with ?schedule= opened the modal; a reload must not open it again.
        $this->js("history.replaceState(history.state, '', location.pathname)");
    }

    public function backupNow(string $backupUuid): void
    {
        try {
            $this->authorize('manageBackups', $this->database);
            $backup = $this->database->scheduledBackups()->where('uuid', $backupUuid)->firstOrFail();
            if (! str($this->database->status)->startsWith('running')) {
                $this->dispatch('error', 'The database must be running to start a backup.');

                return;
            }
            DatabaseBackupJob::dispatch($backup);
            $this->dispatch('success', 'Backup queued.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function setCustomType()
    {
        try {
            $this->authorize('update', $this->database);

            $this->database->custom_type = $this->custom_type;
            $this->database->save();
            $this->dispatch('success', 'Database type set.');
            $this->refreshScheduledBackups();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function delete($scheduled_backup_id): void
    {
        try {
            $this->authorize('delete', $this->database);

            $backup = $this->database->scheduledBackups->find($scheduled_backup_id);
            $backup->delete();
            auditLog('ui.database.backup_schedule_deleted', [
                'team_id' => $this->database->team()?->id,
                'database_uuid' => $this->database->uuid,
                'database_name' => $this->database->name,
                'backup_uuid' => $backup->uuid,
            ]);
            $this->dispatch('success', 'Scheduled backup deleted.');
            $this->refreshScheduledBackups();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshScheduledBackups(?int $id = null): void
    {
        $this->database->refresh();
        $this->dispatch('refreshScheduledBackups');
    }

    public function render(): View
    {
        $this->database->loadMissing(['scheduledBackups.s3', 'scheduledBackups.s3Storages']);

        return view('livewire.project.database.scheduled-backups');
    }
}
