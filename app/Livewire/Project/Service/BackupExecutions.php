<?php

namespace App\Livewire\Project\Service;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class BackupExecutions extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public Service $service;

    public int $perPage = 10;

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

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $this->resetPage('executionsPage');
    }

    public function openExecution(string $executionUuid): void
    {
        $this->authorize('view', $this->service);
        $execution = $this->executionQuery($executionUuid)->first();
        abort_unless($execution, 404);
        $this->selectedExecution = $this->formatExecutions(collect([$execution]))->first();
        $this->executionModalOpen = true;
    }

    public function closeExecutionModal(): void
    {
        $this->executionModalOpen = false;
        $this->selectedExecution = null;
    }

    public function render(): View
    {
        $this->authorize('view', $this->service);
        $executions = $this->executionQuery()->paginate($this->perPage, pageName: 'executionsPage');
        if ($executions->currentPage() > $executions->lastPage()) {
            $this->setPage($executions->lastPage(), 'executionsPage');
            $executions = $this->executionQuery()->paginate($this->perPage, pageName: 'executionsPage');
        }
        $executions->setCollection($this->formatExecutions($executions->getCollection()));

        return view('livewire.project.service.backup-executions', [
            'executions' => $executions,
        ]);
    }

    private function executionQuery(?string $uuid = null): Builder
    {
        $databaseScheduleIds = ScheduledDatabaseBackup::query()
            ->where('database_type', (new ServiceDatabase)->getMorphClass())
            ->whereHasMorph('database', [ServiceDatabase::class], fn ($query) => $query->where('service_id', $this->service->id))
            ->select('id');
        $volumeScheduleIds = ScheduledVolumeBackup::query()
            ->forService($this->service)
            ->select('id');

        $databaseExecutions = ScheduledDatabaseBackupExecution::query()
            ->select('id', 'uuid', 'created_at')
            ->selectRaw("'database' as type")
            ->whereIn('scheduled_database_backup_id', $databaseScheduleIds)
            ->when($uuid !== null, fn ($query) => $query->where('uuid', $uuid));
        $volumeExecutions = ScheduledVolumeBackupExecution::query()
            ->select('id', 'uuid', 'created_at')
            ->selectRaw("'storage' as type")
            ->whereIn('scheduled_volume_backup_id', $volumeScheduleIds)
            ->when($uuid !== null, fn ($query) => $query->where('uuid', $uuid));

        return $databaseExecutions->toBase()
            ->unionAll($volumeExecutions->toBase())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->orderBy('type');
    }

    private function formatExecutions(Collection $rows): Collection
    {
        $databaseExecutions = ScheduledDatabaseBackupExecution::query()
            ->with(['scheduledDatabaseBackup.database', 'scheduledDatabaseBackup.s3'])
            ->whereIn('id', $rows->where('type', 'database')->pluck('id'))
            ->get()->keyBy('id');
        $volumeExecutions = ScheduledVolumeBackupExecution::query()
            ->with(['scheduledVolumeBackup.backupable.resource', 's3'])
            ->whereIn('id', $rows->where('type', 'storage')->pluck('id'))
            ->get()->keyBy('id');

        return $rows->map(function (object $row) use ($databaseExecutions, $volumeExecutions): array {
            $isDatabase = $row->type === 'database';
            $execution = $isDatabase ? $databaseExecutions->get($row->id) : $volumeExecutions->get($row->id);
            $schedule = $isDatabase ? $execution->scheduledDatabaseBackup : $execution->scheduledVolumeBackup;
            $storage = $isDatabase ? ($schedule->save_s3 ? $schedule->s3 : null) : $execution->s3;
            if ($storage?->team_id !== currentTeam()->id) {
                $storage = null;
            }
            $storageLabel = $storage ? $storage->name.' (bucket: '.$storage->bucket.')' : 'Unavailable';
            if ($isDatabase && ! $schedule->save_s3) {
                $storageLabel = 'Not configured';
            } elseif (! $isDatabase && ! $execution->s3_storage_id && ! $execution->s3_uploaded && ! $execution->s3_storage_deleted) {
                $storageLabel = 'No destination recorded';
            }

            return [
                'id' => $row->type.':'.$execution->id,
                'uuid' => $execution->uuid,
                'target' => $isDatabase ? ($schedule->database->human_name ?: $schedule->database->name) : $schedule->targetName(),
                'type' => $isDatabase ? 'Database' : $schedule->targetType(),
                'schedule' => $schedule->frequency,
                's3_tooltip' => ($isDatabase ? 'Current schedule S3 storage: ' : 'S3 storage: ').$storageLabel,
                'status' => $execution->status,
                'started_at' => $execution->created_at,
                'size' => $execution->size,
                'message' => $execution->message,
                'filename' => $execution->filename,
                'download_url' => $execution->status === 'success' && ! $execution->local_storage_deleted
                    ? route($isDatabase ? 'download.backup' : 'download.volume-backup', $execution->id)
                    : null,
            ];
        });
    }
}
