<?php

namespace App\Livewire\Project\Service;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class BackupExecutions extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public bool $executionModalOpen = false;

    public ?array $selectedExecution = null;

    public function getListeners(): array
    {
        $teamId = currentTeam()->id;

        return [
            'modalClosed' => 'closeExecutionModal',
            "echo-private:team.{$teamId},BackupCreated" => '$refresh',
        ];
    }

    public function mount(Service $service): void
    {
        abort_unless($service->environment?->project?->team_id === currentTeam()->id, 404);
        $this->service = $service;
        $this->authorize('view', $this->service);
    }

    public function openExecution(string $executionUuid): void
    {
        $this->selectedExecution = $this->executions()->firstWhere('uuid', $executionUuid);
        abort_unless($this->selectedExecution, 404);
        $this->executionModalOpen = true;
    }

    public function closeExecutionModal(): void
    {
        $this->executionModalOpen = false;
        $this->selectedExecution = null;
    }

    public function render(): View
    {
        return view('livewire.project.service.backup-executions', [
            'executions' => $this->executions(),
        ]);
    }

    private function executions(): Collection
    {
        $databaseScheduleIds = ScheduledDatabaseBackup::query()
            ->where('database_type', (new ServiceDatabase)->getMorphClass())
            ->whereHasMorph('database', [ServiceDatabase::class], fn ($query) => $query->where('service_id', $this->service->id))
            ->pluck('id');

        $databaseExecutions = ScheduledDatabaseBackupExecution::query()
            ->with('scheduledDatabaseBackup.database')
            ->whereIn('scheduled_database_backup_id', $databaseScheduleIds)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (ScheduledDatabaseBackupExecution $execution): array => [
                'id' => 'database:'.$execution->id,
                'uuid' => $execution->uuid,
                'target' => $execution->scheduledDatabaseBackup->database->human_name ?: $execution->scheduledDatabaseBackup->database->name,
                'type' => 'Database',
                'schedule' => $execution->scheduledDatabaseBackup->frequency,
                'status' => $execution->status,
                'started_at' => $execution->created_at,
                'size' => $execution->size,
                'message' => $execution->message,
                'filename' => $execution->filename,
                'download_url' => $execution->status === 'success' && ! $execution->local_storage_deleted
                    ? route('download.backup', $execution->id)
                    : null,
            ]);

        $volumeSchedules = ScheduledVolumeBackup::query()
            ->with('backupable.resource')
            ->forService($this->service)
            ->get()
            ->keyBy('id');
        $volumeExecutions = ScheduledVolumeBackupExecution::query()
            ->whereIn('scheduled_volume_backup_id', $volumeSchedules->keys())
            ->latest()
            ->limit(100)
            ->get()
            ->map(function (ScheduledVolumeBackupExecution $execution) use ($volumeSchedules): array {
                $schedule = $volumeSchedules->get($execution->scheduled_volume_backup_id);

                return [
                    'id' => 'storage:'.$execution->id,
                    'uuid' => $execution->uuid,
                    'target' => $schedule->targetName(),
                    'type' => $schedule->targetType(),
                    'schedule' => $schedule->frequency,
                    'status' => $execution->status,
                    'started_at' => $execution->created_at,
                    'size' => $execution->size,
                    'message' => $execution->message,
                    'filename' => $execution->filename,
                    'download_url' => $execution->status === 'success' && ! $execution->local_storage_deleted
                        ? route('download.volume-backup', $execution->id)
                        : null,
                ];
            });

        return $databaseExecutions->concat($volumeExecutions)->sortByDesc('started_at')->values();
    }
}
