<?php

namespace App\Livewire;

use App\Enums\ApplicationDeploymentStatus;
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

        $availableServers = $this->distinctValues($baseQuery, 'server_name');
        $availableSources = $this->distinctValues($baseQuery, 'git_type');
        $serverColumn = $baseQuery->getQuery()->getGrammar()->wrap('server_name');
        $sourceColumn = $baseQuery->getQuery()->getGrammar()->wrap('git_type');
        $statusColumn = $baseQuery->getQuery()->getGrammar()->wrap('status');

        $filteredQuery = (clone $baseQuery)
            ->when(
                filled($this->status),
                fn (Builder $query) => $query->whereRaw("TRIM({$statusColumn}) = ?", [trim($this->status ?? '')])
            )
            ->when(filled($this->project), function (Builder $query) {
                $query->whereHas('application.environment.project', function (Builder $projectQuery) {
                    $wrappedProjectName = $projectQuery->getQuery()->getGrammar()->wrap('name');

                    $projectQuery->whereRaw("TRIM({$wrappedProjectName}) = ?", [trim($this->project ?? '')]);
                });
            })
            ->when(
                filled($this->server) && $availableServers->count() > 1,
                fn (Builder $query) => $query->whereRaw("TRIM({$serverColumn}) = ?", [trim($this->server ?? '')])
            )
            ->when(
                filled($this->source) && $availableSources->count() > 1,
                fn (Builder $query) => $query->whereRaw("TRIM({$sourceColumn}) = ?", [trim($this->source ?? '')])
            );

        $deployments = (clone $filteredQuery)
            ->latest()
            ->paginate(25);

        return view('livewire.deployments', [
            'deployments' => $deployments,
            'availableProjects' => $this->availableProjects($team->id),
            'availableServers' => $availableServers,
            'availableSources' => $availableSources,
            'availableStatuses' => $this->distinctValues($baseQuery, 'status'),
            'isPollingActive' => (clone $filteredQuery)
                ->whereRaw("TRIM({$statusColumn}) IN (?, ?)", [
                    ApplicationDeploymentStatus::QUEUED->value,
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                ])
                ->exists(),
        ]);
    }

    private function availableProjects(int $teamId): Collection
    {
        $query = ApplicationDeploymentQueue::query()
            ->join('applications', 'application_deployment_queues.application_id', '=', 'applications.id')
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId);

        $wrappedProjectName = $query->getQuery()->getGrammar()->wrap('projects.name');

        return $query
            ->selectRaw("TRIM({$wrappedProjectName}) as value")
            ->whereNotNull('projects.name')
            ->whereRaw("TRIM({$wrappedProjectName}) != ''")
            ->distinct()
            ->orderBy('value')
            ->pluck('value');
    }

    private function distinctValues(Builder $query, string $column): Collection
    {
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);

        return (clone $query)
            ->selectRaw("TRIM({$wrappedColumn}) as value")
            ->whereNotNull($column)
            ->whereRaw("TRIM({$wrappedColumn}) != ''")
            ->distinct()
            ->orderBy('value')
            ->pluck('value');
    }
}
