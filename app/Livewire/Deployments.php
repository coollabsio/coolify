<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class Deployments extends Component
{
    use WithPagination;

    public ?string $status = null;

    public ?string $project = null;

    public ?string $server = null;

    public ?string $source = null;

    protected $queryString = [
        'status' => ['except' => ''],
        'project' => ['except' => ''],
        'server' => ['except' => ''],
        'source' => ['except' => ''],
    ];

    public function updating(string $name): void
    {
        if (in_array($name, ['status', 'project', 'server', 'source'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $team = currentTeam();

        abort_unless($team, 403);

        $baseQuery = ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->whereHas('application.environment.project', function (Builder $query) use ($team) {
                $query->where('team_id', $team->id);
            });

        $filteredQuery = (clone $baseQuery)
            ->when($this->status, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->project, function (Builder $query) {
                $query->whereHas('application.environment.project', function (Builder $projectQuery) {
                    $projectQuery->where('name', $this->project);
                });
            })
            ->when($this->server, fn (Builder $query) => $query->where('server_name', $this->server))
            ->when($this->source, fn (Builder $query) => $query->where('git_type', $this->source));

        $deployments = (clone $filteredQuery)
            ->latest()
            ->paginate(25);

        return view('livewire.deployments', [
            'deployments' => $deployments,
            'availableProjects' => $this->availableProjects($baseQuery, $team->id),
            'availableServers' => $this->distinctValues($baseQuery, 'server_name'),
            'availableSources' => $this->distinctValues($baseQuery, 'git_type'),
            'availableStatuses' => $this->distinctValues($baseQuery, 'status'),
            'isPollingActive' => (clone $filteredQuery)
                ->whereIn('status', ['queued', 'in_progress'])
                ->exists(),
        ]);
    }

    private function availableProjects(Builder $query, int $teamId): Collection
    {
        return ApplicationDeploymentQueue::query()
            ->join('applications', 'application_deployment_queues.application_id', '=', 'applications.id')
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId)
            ->when($this->status, fn (Builder $builder) => $builder->where('application_deployment_queues.status', $this->status))
            ->when($this->server, fn (Builder $builder) => $builder->where('application_deployment_queues.server_name', $this->server))
            ->when($this->source, fn (Builder $builder) => $builder->where('application_deployment_queues.git_type', $this->source))
            ->select('projects.name')
            ->distinct()
            ->orderBy('projects.name')
            ->pluck('projects.name');
    }

    private function distinctValues(Builder $query, string $column): Collection
    {
        return (clone $query)
            ->select($column)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column);
    }
}
