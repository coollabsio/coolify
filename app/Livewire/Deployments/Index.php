<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public ?string $projectFilter = null;

    public ?string $serverFilter = null;

    public ?string $sourceFilter = null;

    public ?string $statusFilter = null;

    public array $projects = [];

    public array $servers = [];

    public array $sources = [];

    protected $queryString = [
        'projectFilter' => ['except' => ''],
        'serverFilter' => ['except' => ''],
        'sourceFilter' => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    public function mount()
    {
        $teamId = currentTeam()->id;

        $this->projects = Project::ownedByCurrentTeam()
            ->get()
            ->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name, 'uuid' => $p->uuid])
            ->values()
            ->toArray();

        $this->servers = Server::ownedByCurrentTeam()
            ->get()
            ->map(fn ($s) => ['id' => (string) $s->id, 'name' => $s->name])
            ->values()
            ->toArray();

        $this->sources = ApplicationDeploymentQueue::whereIn('application_id', function ($q) use ($teamId) {
            $q->select('id')
                ->from('applications')
                ->whereIn('environment_id', function ($q2) use ($teamId) {
                    $q2->select('id')
                        ->from('environments')
                        ->whereIn('project_id', function ($q3) use ($teamId) {
                            $q3->select('id')
                                ->from('projects')
                                ->where('team_id', $teamId);
                        });
                });
        })
            ->whereNotNull('git_type')
            ->distinct()
            ->pluck('git_type')
            ->map(fn ($type) => ['id' => $type, 'name' => ucfirst($type)])
            ->values()
            ->toArray();
    }

    public function updating($property)
    {
        if (in_array($property, ['projectFilter', 'serverFilter', 'sourceFilter', 'statusFilter'])) {
            $this->resetPage();
        }
    }

    public function clearFilters()
    {
        $this->projectFilter = null;
        $this->serverFilter = null;
        $this->sourceFilter = null;
        $this->statusFilter = null;
        $this->resetPage();
    }

    public function render()
    {
        $teamId = currentTeam()->id;

        $query = ApplicationDeploymentQueue::query()
            ->select('application_deployment_queues.*')
            ->join('applications', 'application_deployment_queues.application_id', '=', 'applications.id')
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId);

        if ($this->projectFilter) {
            $query->where('projects.id', $this->projectFilter);
        }

        if ($this->serverFilter) {
            $query->where('application_deployment_queues.server_id', $this->serverFilter);
        }

        if ($this->sourceFilter) {
            $query->where('application_deployment_queues.git_type', $this->sourceFilter);
        }

        if ($this->statusFilter) {
            $query->where('application_deployment_queues.status', $this->statusFilter);
        }

        $query->orderBy('application_deployment_queues.created_at', 'desc');

        $deployments = $query->with('application.environment.project')->paginate(25);

        $hasActiveFilters = $this->projectFilter || $this->serverFilter || $this->sourceFilter || $this->statusFilter;

        return view('livewire.deployments.index', [
            'deployments' => $deployments,
            'hasActiveFilters' => $hasActiveFilters,
        ]);
    }
}
