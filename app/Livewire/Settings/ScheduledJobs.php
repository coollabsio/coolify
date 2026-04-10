<?php

namespace App\Livewire\Settings;

use App\Models\DockerCleanupExecution;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Services\SchedulerLogParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

class ScheduledJobs extends Component
{
    public string $filterType = 'all';

    public string $filterDate = 'last_24h';

    public int $skipPage = 0;

    public int $skipDefaultTake = 20;

    public bool $showSkipNext = false;

    public bool $showSkipPrev = false;

    public int $skipCurrentPage = 1;

    public int $skipTotalCount = 0;

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
        $this->skipPage = 0;
        $this->loadData();
    }

    public function updatedFilterDate(): void
    {
        $this->skipPage = 0;
        $this->loadData();
    }

    public function skipNextPage(): void
    {
        $this->skipPage += $this->skipDefaultTake;
        $this->showSkipPrev = true;
        $this->loadData();
    }

    public function skipPreviousPage(): void
    {
        $this->skipPage -= $this->skipDefaultTake;
        if ($this->skipPage < 0) {
            $this->skipPage = 0;
        }
        $this->showSkipPrev = $this->skipPage > 0;
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
        $allSkips = $parser->getRecentSkips(500, $teamId);
        $this->skipTotalCount = $allSkips->count();
        $this->skipLogs = $this->enrichSkipLogsWithLinks(
            $allSkips->slice($this->skipPage, $this->skipDefaultTake)->values()
        );
        $this->showSkipPrev = $this->skipPage > 0;
        $this->showSkipNext = ($this->skipPage + $this->skipDefaultTake) < $this->skipTotalCount;
        $this->skipCurrentPage = intval($this->skipPage / $this->skipDefaultTake) + 1;
        $this->managerRuns = $parser->getRecentRuns(30, $teamId);
    }

    private function enrichSkipLogsWithLinks(Collection $skipLogs): Collection
    {
        $taskIds = $skipLogs->where('type', 'task')->pluck('context.task_id')->filter()->unique()->values();
        $backupIds = $skipLogs->where('type', 'backup')->pluck('context.backup_id')->filter()->unique()->values();
        $serverIds = $skipLogs->where('type', 'docker_cleanup')->pluck('context.server_id')->filter()->unique()->values();

        $tasks = $taskIds->isNotEmpty()
            ? ScheduledTask::with(['application.environment.project', 'service.environment.project'])->whereIn('id', $taskIds)->get()->keyBy('id')
            : collect();

        $backups = $backupIds->isNotEmpty()
            ? ScheduledDatabaseBackup::with(['database.environment.project'])->whereIn('id', $backupIds)->get()->keyBy('id')
            : collect();

        $servers = $serverIds->isNotEmpty()
            ? Server::whereIn('id', $serverIds)->get()->keyBy('id')
            : collect();

        return $skipLogs->map(function (array $skip) use ($tasks, $backups, $servers): array {
            $skip['link'] = null;
            $skip['resource_name'] = null;

            if ($skip['type'] === 'task') {
                $task = $tasks->get($skip['context']['task_id'] ?? null);
                if ($task) {
                    $skip['resource_name'] = $skip['context']['task_name'] ?? $task->name;
                    $resource = $task->application ?? $task->service;
                    $environment = $resource?->environment;
                    $project = $environment?->project;
                    if ($project && $environment && $resource) {
                        $routeName = $task->application_id
                            ? 'project.application.scheduled-tasks'
                            : 'project.service.scheduled-tasks';
                        $routeKey = $task->application_id ? 'application_uuid' : 'service_uuid';
                        $skip['link'] = route($routeName, [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $environment->uuid,
                            $routeKey => $resource->uuid,
                            'task_uuid' => $task->uuid,
                        ]);
                    }
                }
            } elseif ($skip['type'] === 'backup') {
                $backup = $backups->get($skip['context']['backup_id'] ?? null);
                if ($backup) {
                    $database = $backup->database;
                    $skip['resource_name'] = $database?->name ?? 'Database backup';
                    $environment = $database?->environment;
                    $project = $environment?->project;
                    if ($project && $environment && $database) {
                        $skip['link'] = route('project.database.backup.index', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $environment->uuid,
                            'database_uuid' => $database->uuid,
                        ]);
                    }
                }
            } elseif ($skip['type'] === 'docker_cleanup') {
                $server = $servers->get($skip['context']['server_id'] ?? null);
                if ($server) {
                    $skip['resource_name'] = $server->name;
                    $skip['link'] = route('server.show', ['server_uuid' => $server->uuid]);
                }
            }

            return $skip;
        });
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
