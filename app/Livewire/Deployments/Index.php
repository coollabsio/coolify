<?php

namespace App\Livewire\Deployments;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $filterProject = '';

    public string $filterServer = '';

    public string $filterSource = '';

    public string $filterStatus = '';

    /** @var array<string, mixed> */
    protected $queryString = [
        'filterProject' => ['except' => ''],
        'filterServer' => ['except' => ''],
        'filterSource' => ['except' => ''],
        'filterStatus' => ['except' => ''],
    ];

    public function updatedFilterProject(): void
    {
        $this->resetPage();
    }

    public function updatedFilterServer(): void
    {
        $this->resetPage();
    }

    public function updatedFilterSource(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function reloadDeployments(): void
    {
        // Used by wire:poll; WithPagination will re-query on next render.
    }

    public function clearFilters(): void
    {
        $this->filterProject = '';
        $this->filterServer = '';
        $this->filterSource = '';
        $this->filterStatus = '';
        $this->resetPage();
    }

    public function deploymentPath(ApplicationDeploymentQueue $deployment): string
    {
        if (filled($deployment->deployment_url)) {
            return $deployment->deployment_url;
        }

        $application = $deployment->application;

        return route('project.application.deployment.show', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
            'deployment_uuid' => $deployment->deployment_uuid,
        ], false);
    }

    public function render()
    {
        $this->sanitizeFilters();

        $projects = Project::ownedByCurrentTeam()->orderByRaw('LOWER(name)')->get(['id', 'uuid', 'name']);
        $teamServers = Server::ownedByCurrentTeam()->orderByRaw('LOWER(name)')->get(['id', 'name']);

        $baseForMeta = $this->scopedDeploymentQuery();
        $gitTypes = (clone $baseForMeta)
            ->whereNotNull('git_type')
            ->where('git_type', '!=', '')
            ->distinct()
            ->orderBy('git_type')
            ->pluck('git_type');
        $hasNullGitType = (clone $baseForMeta)->where(function (Builder $q): void {
            $q->whereNull('git_type')->orWhere('git_type', '');
        })->exists();

        $statusValues = (clone $baseForMeta)
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        $showProjectFilter = $projects->count() > 1;
        $showServerFilter = $teamServers->count() > 1;
        $sourceOptionCount = $gitTypes->count() + ($hasNullGitType ? 1 : 0);
        $showSourceFilter = $sourceOptionCount > 1;
        $showStatusFilter = $statusValues->count() > 1;

        $deployments = $this->filteredDeploymentQuery()
            ->with([
                'application.environment.project',
                'application.destination.server',
            ])
            ->paginate(15);

        return view('livewire.deployments.index', [
            'projects' => $projects,
            'teamServers' => $teamServers,
            'gitTypes' => $gitTypes,
            'hasNullGitType' => $hasNullGitType,
            'statusValues' => $statusValues,
            'showProjectFilter' => $showProjectFilter,
            'showServerFilter' => $showServerFilter,
            'showSourceFilter' => $showSourceFilter,
            'showStatusFilter' => $showStatusFilter,
            'deployments' => $deployments,
        ]);
    }

    protected function scopedDeploymentQuery(): Builder
    {
        return ApplicationDeploymentQueue::query()
            ->whereHas('application', function (Builder $query): void {
                $query->whereRelation('environment.project', 'team_id', currentTeam()->id);
            });
    }

    protected function filteredDeploymentQuery(): Builder
    {
        $query = $this->scopedDeploymentQuery();

        if ($this->filterProject !== '') {
            $query->whereHas('application.environment.project', function (Builder $q): void {
                $q->where('uuid', $this->filterProject);
            });
        }

        if ($this->filterServer !== '' && is_numeric($this->filterServer)) {
            $query->where('server_id', (int) $this->filterServer);
        }

        if ($this->filterSource !== '') {
            if ($this->filterSource === '__none__') {
                $query->where(function (Builder $q): void {
                    $q->whereNull('git_type')->orWhere('git_type', '');
                });
            } else {
                $query->where('git_type', $this->filterSource);
            }
        }

        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }

        return $query->orderByDesc('created_at');
    }

    protected function sanitizeFilters(): void
    {
        $projectUuids = Project::ownedByCurrentTeam()->pluck('uuid')->all();
        if ($this->filterProject !== '' && ! in_array($this->filterProject, $projectUuids, true)) {
            $this->filterProject = '';
        }

        $serverIds = Server::ownedByCurrentTeam()->pluck('id')->all();
        if ($this->filterServer !== '' && (! is_numeric($this->filterServer) || ! in_array((int) $this->filterServer, $serverIds, true))) {
            $this->filterServer = '';
        }

        $base = $this->scopedDeploymentQuery();
        $allowedStatuses = (clone $base)->distinct()->pluck('status')->filter()->all();
        if ($this->filterStatus !== '' && ! in_array($this->filterStatus, $allowedStatuses, true)) {
            $this->filterStatus = '';
        }

        $allowedGit = (clone $base)
            ->whereNotNull('git_type')
            ->where('git_type', '!=', '')
            ->distinct()
            ->pluck('git_type')
            ->all();
        $allowsNullGit = (clone $base)->where(function (Builder $q): void {
            $q->whereNull('git_type')->orWhere('git_type', '');
        })->exists();

        if ($this->filterSource !== '') {
            if ($this->filterSource === '__none__') {
                if (! $allowsNullGit) {
                    $this->filterSource = '';
                }
            } elseif (! in_array($this->filterSource, $allowedGit, true)) {
                $this->filterSource = '';
            }
        }
    }
}
