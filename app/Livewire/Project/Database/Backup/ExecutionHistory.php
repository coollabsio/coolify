<?php

namespace App\Livewire\Project\Database\Backup;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Traits\ListensToTeamChannel;
use App\Traits\PreparesBackupDownloads;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Executions of every backup schedule of one standalone database.
 */
class ExecutionHistory extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use PreparesBackupDownloads;
    use WithPagination;

    private const SORT_COLUMNS = ['schedule', 'status', 'started', 'size'];

    public $database;

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

    public function mount($database): void
    {
        $this->database = $database;
        $this->authorize('view', $this->database);
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $this->resetPage('executionsPage');
    }

    public function sortExecutions(string $sortBy): void
    {
        [$column, $direction] = array_pad(explode('_', $sortBy, 2), 2, null);
        if (! in_array($column, self::SORT_COLUMNS, true) || ! in_array($direction, ['asc', 'desc'], true)) {
            return;
        }
        $this->sortBy = $sortBy;
        $this->resetPage('executionsPage');
    }

    public function openExecution(string $executionUuid): void
    {
        $this->authorize('view', $this->database);
        $execution = $this->executionQuery()->where('uuid', $executionUuid)->first();
        abort_unless($execution, 404);
        $this->selectedExecution = $this->formatExecution($execution);
        $this->executionModalOpen = true;
    }

    public function downloadExecution(string $executionUuid): void
    {
        $this->authorize('manageBackups', $this->database);
        $execution = $this->executionQuery()->where('uuid', $executionUuid)->first();
        abort_unless($execution, 404);

        try {
            $this->prepareBackupDownload(
                $execution->local_storage_deleted ? null : $execution->scheduledDatabaseBackup->server(),
                $execution->filename,
                route('download.backup', $execution->id),
            );
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
        $this->authorize('view', $this->database);
        $executions = $this->executionQuery()->paginate($this->perPage, pageName: 'executionsPage');
        if ($executions->currentPage() > $executions->lastPage()) {
            $this->setPage($executions->lastPage(), 'executionsPage');
            $executions = $this->executionQuery()->paginate($this->perPage, pageName: 'executionsPage');
        }
        $executions->setCollection($executions->getCollection()->map(fn ($execution) => $this->formatExecution($execution)));

        return view('livewire.project.database.backup.execution-history', [
            'executions' => $executions,
            's3Downloads' => $this->s3BackupDownloadsEnabled(),
        ]);
    }

    private function executionQuery(): Builder
    {
        [$column, $direction] = explode('_', $this->sortBy, 2);

        return ScheduledDatabaseBackupExecution::query()
            ->with(['scheduledDatabaseBackup', 's3Replicas.s3'])
            ->whereIn('scheduled_database_backup_id', $this->database->scheduledBackups()->select('id'))
            ->when($column === 'schedule', fn ($query) => $query->orderBy(
                ScheduledDatabaseBackup::query()->select('frequency')
                    ->whereColumn('scheduled_database_backups.id', 'scheduled_database_backup_executions.scheduled_database_backup_id'),
                $direction,
            ))
            ->when($column === 'status', fn ($query) => $query->orderBy('status', $direction))
            ->when($column === 'size', fn ($query) => $query->orderByRaw("CAST(NULLIF(size, '') AS BIGINT) {$direction}"))
            ->orderBy('created_at', $column === 'started' ? $direction : 'desc')
            ->orderByDesc('id');
    }

    private function formatExecution(ScheduledDatabaseBackupExecution $execution): array
    {
        $teamId = $this->database->team()?->id;
        $s3Storages = $execution->s3Replicas
            ->map(fn ($replica) => $replica->s3?->team_id === $teamId
                ? $replica->s3->name.' ('.($replica->s3->isS3() ? 'bucket: '.$replica->s3->bucket : $replica->s3->type->label()).')'
                : 'Unavailable')
            ->implode(', ');

        return [
            'id' => $execution->id,
            'uuid' => $execution->uuid,
            'schedule' => $execution->scheduledDatabaseBackup->frequency,
            's3_names' => $execution->s3Replicas->map(fn ($replica) => $replica->s3?->team_id === $teamId ? $replica->s3->name : 'Unavailable')->values(),
            's3_tooltip' => $s3Storages === '' ? null : 'S3 storage: '.$s3Storages,
            'status' => $execution->status,
            'started_at' => $execution->created_at,
            'finished_at' => $execution->finished_at ?? $execution->updated_at,
            'size' => $execution->size,
            'message' => $execution->message,
            'filename' => $execution->filename,
            'download_url' => $execution->status === 'success' && $execution->isDownloadable()
                ? route('download.backup', $execution->id)
                : null,
        ];
    }
}
