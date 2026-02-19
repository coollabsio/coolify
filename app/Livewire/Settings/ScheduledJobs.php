<?php

namespace App\Livewire\Settings;

use App\Models\DockerCleanupExecution;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use App\Services\SchedulerLogParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

class ScheduledJobs extends Component
{
    public string $filterType = 'all';

    public string $filterDate = 'last_24h';

    protected Collection $executions;

    protected Collection $skipLogs;

    protected Collection $managerRuns;

    public function boot(): void
    {
        $this->executions = collect();
        $this->skipLogs = collect();
        $this->managerRuns = collect();
    }

    public function mount(): void
    {
        if (! isInstanceAdmin()) {
            redirect()->route('dashboard');

            return;
        }

        $this->loadData();
    }

    public function updatedFilterType(): void
    {
        $this->loadData();
    }

    public function updatedFilterDate(): void
    {
        $this->loadData();
    }

    public function refresh(): void
    {
        $this->loadData();
    }

    public function render()
    {
        return view('livewire.settings.scheduled-jobs', [
            'executions' => $this->executions,
            'skipLogs' => $this->skipLogs,
            'managerRuns' => $this->managerRuns,
        ]);
    }

    private function loadData(?int $teamId = null): void
    {
        $this->executions = $this->getExecutions($teamId);

        $parser = new SchedulerLogParser;
        $this->skipLogs = $parser->getRecentSkips(50, $teamId);
        $this->managerRuns = $parser->getRecentRuns(30, $teamId);
    }

    private function getExecutions(?int $teamId = null): Collection
    {
        $dateFrom = $this->getDateFrom();

        $backups = collect();
        $tasks = collect();
        $cleanups = collect();

        if ($this->filterType === 'all' || $this->filterType === 'backup') {
            $backups = $this->getBackupExecutions($dateFrom, $teamId);
        }

        if ($this->filterType === 'all' || $this->filterType === 'task') {
            $tasks = $this->getTaskExecutions($dateFrom, $teamId);
        }

        if ($this->filterType === 'all' || $this->filterType === 'cleanup') {
            $cleanups = $this->getCleanupExecutions($dateFrom, $teamId);
        }

        return $backups->concat($tasks)->concat($cleanups)
            ->sortByDesc('created_at')
            ->values()
            ->take(100);
    }

    private function getBackupExecutions(?Carbon $dateFrom, ?int $teamId): Collection
    {
        $query = ScheduledDatabaseBackupExecution::with(['scheduledDatabaseBackup.database', 'scheduledDatabaseBackup.team'])
            ->where('status', 'failed')
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($teamId, fn ($q) => $q->whereRelation('scheduledDatabaseBackup.team', 'id', $teamId))
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return $query->map(function ($execution) {
            $backup = $execution->scheduledDatabaseBackup;
            $database = $backup?->database;
            $server = $backup?->server();

            return [
                'id' => $execution->id,
                'type' => 'backup',
                'status' => $execution->status ?? 'unknown',
                'resource_name' => $database?->name ?? 'Deleted database',
                'resource_type' => $database ? class_basename($database) : null,
                'server_name' => $server?->name ?? 'Unknown',
                'server_id' => $server?->id,
                'team_id' => $backup?->team_id,
                'created_at' => $execution->created_at,
                'finished_at' => $execution->updated_at,
                'message' => $execution->message,
                'size' => $execution->size ?? null,
            ];
        });
    }

    private function getTaskExecutions(?Carbon $dateFrom, ?int $teamId): Collection
    {
        $query = ScheduledTaskExecution::with(['scheduledTask.application', 'scheduledTask.service'])
            ->where('status', 'failed')
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($teamId, function ($q) use ($teamId) {
                $q->where(function ($sub) use ($teamId) {
                    $sub->whereRelation('scheduledTask.application.environment.project.team', 'id', $teamId)
                        ->orWhereRelation('scheduledTask.service.environment.project.team', 'id', $teamId);
                });
            })
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return $query->map(function ($execution) {
            $task = $execution->scheduledTask;
            $resource = $task?->application ?? $task?->service;
            $server = $task?->server();
            $teamId = $server?->team_id;

            return [
                'id' => $execution->id,
                'type' => 'task',
                'status' => $execution->status ?? 'unknown',
                'resource_name' => $task?->name ?? 'Deleted task',
                'resource_type' => $resource ? class_basename($resource) : null,
                'server_name' => $server?->name ?? 'Unknown',
                'server_id' => $server?->id,
                'team_id' => $teamId,
                'created_at' => $execution->created_at,
                'finished_at' => $execution->finished_at,
                'message' => $execution->message,
                'size' => null,
            ];
        });
    }

    private function getCleanupExecutions(?Carbon $dateFrom, ?int $teamId): Collection
    {
        $query = DockerCleanupExecution::with(['server'])
            ->where('status', 'failed')
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($teamId, fn ($q) => $q->whereRelation('server', 'team_id', $teamId))
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return $query->map(function ($execution) {
            $server = $execution->server;

            return [
                'id' => $execution->id,
                'type' => 'cleanup',
                'status' => $execution->status ?? 'unknown',
                'resource_name' => $server?->name ?? 'Deleted server',
                'resource_type' => 'Server',
                'server_name' => $server?->name ?? 'Unknown',
                'server_id' => $server?->id,
                'team_id' => $server?->team_id,
                'created_at' => $execution->created_at,
                'finished_at' => $execution->finished_at ?? $execution->updated_at,
                'message' => $execution->message,
                'size' => null,
            ];
        });
    }

    private function getDateFrom(): ?Carbon
    {
        return match ($this->filterDate) {
            'last_24h' => now()->subDay(),
            'last_7d' => now()->subWeek(),
            'last_30d' => now()->subMonth(),
            default => null,
        };
    }
}
