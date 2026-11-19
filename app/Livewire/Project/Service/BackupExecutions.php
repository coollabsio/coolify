<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Traits\ListensToTeamChannel;
use App\Traits\PreparesBackupDownloads;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class BackupExecutions extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use PreparesBackupDownloads;
    use WithPagination;

    public Service $service;

    private const SORT_COLUMNS = [
        'target' => 'sort_target',
        'type' => 'sort_type',
        'schedule' => 'sort_schedule',
        'status' => 'status',
        'started' => 'created_at',
        'size' => 'sort_size',
    ];

    public int $perPage = 10;

    #[Locked]
    public string $sortBy = 'started_desc';

    public bool $executionModalOpen = false;

    public ?array $selectedExecution = null;

    public function getListeners(): array
    {
        return [
            'modalClosed' => 'closeExecutionModal',
            ...$this->teamChannelListeners([
                'BackupCreated' => '$refresh',
            ]),
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

    public function sortExecutions(string $sortBy): void
    {
        [$column, $direction] = array_pad(explode('_', $sortBy, 2), 2, null);
        if (! array_key_exists($column, self::SORT_COLUMNS) || ! in_array($direction, ['asc', 'desc'], true)) {
            return;
        }
        $this->sortBy = $sortBy;
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

    public function downloadExecution(string $executionUuid): void
    {
        $this->authorize('update', $this->service);
        $row = $this->executionQuery($executionUuid)->first();
        abort_unless($row, 404);

        try {
            if ($row->type === 'database') {
                $execution = ScheduledDatabaseBackupExecution::query()->with('scheduledDatabaseBackup')->findOrFail($row->id);
                $server = $execution->scheduledDatabaseBackup->server();
                $streamUrl = route('download.backup', $execution->id);
            } else {
                $execution = ScheduledVolumeBackupExecution::query()->with('scheduledVolumeBackup')->findOrFail($row->id);
                $server = $execution->scheduledVolumeBackup->server();
                $streamUrl = route('download.volume-backup', $execution->id);
            }

            $this->prepareBackupDownload($execution->local_storage_deleted ? null : $server, $execution->filename, $streamUrl);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
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
            's3Downloads' => $this->s3BackupDownloadsEnabled(),
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

        $fileVolumeType = (new LocalFileVolume)->getMorphClass();
        $persistentVolumeType = (new LocalPersistentVolume)->getMorphClass();

        $databaseExecutions = ScheduledDatabaseBackupExecution::query()
            ->select('id', 'uuid', 'created_at', 'status')
            ->selectRaw("'database' as type")
            ->selectRaw("CAST(NULLIF(size, '') AS BIGINT) as sort_size")
            ->selectRaw("(SELECT LOWER(COALESCE(NULLIF(service_databases.human_name, ''), service_databases.name)) FROM scheduled_database_backups JOIN service_databases ON service_databases.id = scheduled_database_backups.database_id WHERE scheduled_database_backups.id = scheduled_database_backup_executions.scheduled_database_backup_id) as sort_target")
            ->selectRaw("'Database' as sort_type")
            ->selectRaw('(SELECT frequency FROM scheduled_database_backups WHERE scheduled_database_backups.id = scheduled_database_backup_executions.scheduled_database_backup_id) as sort_schedule')
            ->whereIn('scheduled_database_backup_id', $databaseScheduleIds)
            ->when($uuid !== null, fn ($query) => $query->where('uuid', $uuid));
        $volumeExecutions = ScheduledVolumeBackupExecution::query()
            ->select('id', 'uuid', 'created_at', 'status')
            ->selectRaw("'storage' as type")
            ->selectRaw('size as sort_size')
            ->selectRaw('(SELECT LOWER(COALESCE((SELECT fs_path FROM local_file_volumes WHERE local_file_volumes.id = scheduled_volume_backups.backupable_id AND scheduled_volume_backups.backupable_type = ?), (SELECT name FROM local_persistent_volumes WHERE local_persistent_volumes.id = scheduled_volume_backups.backupable_id AND scheduled_volume_backups.backupable_type = ?))) FROM scheduled_volume_backups WHERE scheduled_volume_backups.id = scheduled_volume_backup_executions.scheduled_volume_backup_id) as sort_target', [$fileVolumeType, $persistentVolumeType])
            ->selectRaw("(SELECT CASE WHEN backupable_type = ? THEN 'Directory' ELSE 'Volume' END FROM scheduled_volume_backups WHERE scheduled_volume_backups.id = scheduled_volume_backup_executions.scheduled_volume_backup_id) as sort_type", [$fileVolumeType])
            ->selectRaw('(SELECT frequency FROM scheduled_volume_backups WHERE scheduled_volume_backups.id = scheduled_volume_backup_executions.scheduled_volume_backup_id) as sort_schedule')
            ->whereIn('scheduled_volume_backup_id', $volumeScheduleIds)
            ->when($uuid !== null, fn ($query) => $query->where('uuid', $uuid));

        [$sortColumn, $sortDirection] = explode('_', $this->sortBy, 2);

        return $databaseExecutions->toBase()
            ->unionAll($volumeExecutions->toBase())
            ->orderBy(self::SORT_COLUMNS[$sortColumn], $sortDirection)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->orderBy('type');
    }

    private function formatExecutions(Collection $rows): Collection
    {
        $databaseExecutions = ScheduledDatabaseBackupExecution::query()
            ->with(['scheduledDatabaseBackup.database', 's3Replicas.s3'])
            ->whereIn('id', $rows->where('type', 'database')->pluck('id'))
            ->get()->keyBy('id');
        $volumeExecutions = ScheduledVolumeBackupExecution::query()
            ->with(['scheduledVolumeBackup.backupable.resource', 's3Replicas.s3'])
            ->whereIn('id', $rows->where('type', 'storage')->pluck('id'))
            ->get()->keyBy('id');

        $serviceTeamId = $this->service->team()?->id;

        return $rows->map(function (object $row) use ($databaseExecutions, $volumeExecutions, $serviceTeamId): array {
            $isDatabase = $row->type === 'database';
            $execution = $isDatabase ? $databaseExecutions->get($row->id) : $volumeExecutions->get($row->id);
            $schedule = $isDatabase ? $execution->scheduledDatabaseBackup : $execution->scheduledVolumeBackup;
            $s3Storages = $execution->s3Replicas
                ->map(fn ($replica) => $replica->s3?->team_id === $serviceTeamId
                    ? $replica->s3->name.' ('.($replica->s3->isS3() ? 'bucket: '.$replica->s3->bucket : $replica->s3->type->label()).')'
                    : 'Unavailable')
                ->implode(', ');

            return [
                'id' => $row->type.':'.$execution->id,
                'uuid' => $execution->uuid,
                'target' => $isDatabase ? ($schedule->database->human_name ?: $schedule->database->name) : $schedule->targetName(),
                'type' => $isDatabase ? 'Database' : $schedule->targetType(),
                'schedule' => $schedule->frequency,
                's3_tooltip' => $s3Storages === '' ? null : 'S3 storage: '.$s3Storages,
                'status' => $execution->status,
                'started_at' => $execution->created_at,
                'size' => $execution->size,
                'message' => $execution->message,
                'filename' => $execution->filename,
                'download_url' => $execution->status === 'success' && $execution->isDownloadable()
                    ? route($isDatabase ? 'download.backup' : 'download.volume-backup', $execution->id)
                    : null,
            ];
        });
    }
}
