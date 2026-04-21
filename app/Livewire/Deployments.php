<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

class Deployments extends Component
{
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

    public function render()
    {
        $baseQuery = ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->whereHas('application.environment.project', function (Builder $query) {
                $query->where('team_id', currentTeam()->id);
            });

        $deployments = (clone $baseQuery)
            ->when($this->status, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->project, function (Builder $query) {
                $query->whereHas('application.environment.project', function (Builder $projectQuery) {
                    $projectQuery->where('name', $this->project);
                });
            })
            ->when($this->server, fn (Builder $query) => $query->where('server_name', $this->server))
            ->when($this->source, fn (Builder $query) => $query->where('git_type', $this->source))
            ->latest()
            ->get();

        return view('livewire.deployments', [
            'deployments' => $deployments,
            'availableProjects' => $this->availableProjects($baseQuery),
            'availableServers' => $this->availableServers($baseQuery),
            'availableSources' => $this->availableSources($baseQuery),
            'availableStatuses' => $this->availableStatuses($baseQuery),
        ]);
    }

    private function availableProjects(Builder $query): Collection
    {
        return (clone $query)
            ->get()
            ->map(fn (ApplicationDeploymentQueue $deployment) => data_get($deployment, 'application.environment.project.name'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function availableServers(Builder $query): Collection
    {
        return (clone $query)
            ->pluck('server_name')
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function availableSources(Builder $query): Collection
    {
        return (clone $query)
            ->pluck('git_type')
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function availableStatuses(Builder $query): Collection
    {
        return (clone $query)
            ->pluck('status')
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }
}
