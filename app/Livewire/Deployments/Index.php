<?php

namespace App\Livewire\Deployments;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\Project;
use Livewire\Component;
use Livewire\Attributes\Url;

class Index extends Component
{
    #[Url(as: 'project')]
    public ?string $filterProject = null;

    #[Url(as: 'server')]
    public ?string $filterServer = null;

    #[Url(as: 'source')]
    public ?string $filterSource = null;

    #[Url(as: 'status')]
    public ?string $filterStatus = null;

    public $perPage = 25;
    public $deployments;
    public $totalCount;
    public $hasMorePages = false;

    public $projects;
    public $servers;
    public $sources;

    public function mount()
    {
        $teamId = currentTeam()->id;
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::where('team_id', $teamId)->get();
        $this->sources = \App\Models\GithubApp::where('team_id', $teamId)
            ->orWhere('is_system_wide', true)
            ->get();
        $this->loadDeployments();
    }

    public function loadDeployments()
    {
        $teamId = currentTeam()->id;
        $query = ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->whereHas('application', function ($q) use ($teamId) {
                $q->whereIn('environment_id', function ($sub) use ($teamId) {
                    $sub->select('id')
                        ->from('environments')
                        ->whereIn('project_id', function ($p) use ($teamId) {
                            $p->select('id')
                                ->from('projects')
                                ->where('team_id', $teamId);
                        });
                });
            });

        if ($this->filterProject) {
            $query->whereHas('application.environment.project', function ($q) {
                $q->where('uuid', $this->filterProject);
            });
        }

        if ($this->filterServer) {
            $query->where('server_id', function ($q) {
                $q->select('id')->from('servers')->where('uuid', $this->filterServer);
            });
        }

        if ($this->filterSource) {
            $query->whereHas('application', function ($q) {
                $q->where('source_id', $this->filterSource);
            });
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        $this->totalCount = $query->count();
        $deploymentsQuery = $query->orderBy('created_at', 'desc')
            ->take($this->perPage + 1)
            ->get();

        $this->hasMorePages = $deploymentsQuery->count() > $this->perPage;
        $this->deployments = $deploymentsQuery->take($this->perPage);
    }

    public function updated($property)
    {
        if (in_array($property, ['filterProject', 'filterServer', 'filterSource', 'filterStatus'])) {
            $this->perPage = 25;
            $this->loadDeployments();
        }
    }

    public function clearFilters()
    {
        $this->filterProject = null;
        $this->filterServer = null;
        $this->filterSource = null;
        $this->filterStatus = null;
        $this->perPage = 25;
        $this->loadDeployments();
    }

    public function loadMore()
    {
        $this->perPage += 25;
        $this->loadDeployments();
    }

    public function render()
    {
        return view('livewire.deployments.index')
            ->layout('layouts.app');
    }
}
