<?php

namespace App\Livewire\Deployments;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Livewire\Component;

class Index extends Component
{
    public $deployments;

    public int $deployments_count = 0;

    public int $skip = 0;

    public int $defaultTake = 20;

    public bool $showNext = false;

    public bool $showPrev = false;

    public int $currentPage = 1;

    public ?string $filterProject = null;

    public ?string $filterServer = null;

    public ?string $filterStatus = null;

    protected $queryString = [
        'filterProject' => ['except' => null],
        'filterServer' => ['except' => null],
        'filterStatus' => ['except' => null],
    ];

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
        ];
    }

    public function mount()
    {
        $this->loadDeployments();
    }

    public function reloadDeployments()
    {
        $this->loadDeployments();
    }

    public function updatedFilterProject()
    {
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function updatedFilterServer()
    {
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function updatedFilterStatus()
    {
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function clearFilters()
    {
        $this->filterProject = null;
        $this->filterServer = null;
        $this->filterStatus = null;
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function previousPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip - $take;
        }
        $this->skip = $this->skip - $this->defaultTake;
        if ($this->skip < 0) {
            $this->showPrev = false;
            $this->skip = 0;
        }
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function nextPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip + $take;
        }
        $this->showPrev = true;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    private function loadDeployments()
    {
        $servers = Server::ownedByCurrentTeamCached();
        $serverIds = $servers->pluck('id');

        $query = ApplicationDeploymentQueue::with(['application.environment.project'])
            ->whereIn('server_id', $serverIds)
            ->orderBy('created_at', 'desc');

        if ($this->filterServer) {
            $query->where('server_id', $this->filterServer);
        }

        if ($this->filterProject) {
            $query->whereHas('application.environment.project', function ($q) {
                $q->where('uuid', $this->filterProject);
            });
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        $this->deployments_count = $query->count();
        $this->deployments = $query->skip($this->skip)->take($this->defaultTake)->get();

        $this->showNext = false;
        if ($this->deployments->count() !== 0) {
            $this->showNext = true;
            if ($this->deployments->count() < $this->defaultTake) {
                $this->showNext = false;
            }
        }
    }

    private function updateCurrentPage()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
    }

    public function getProjectsProperty()
    {
        $team = currentTeam()->load(['projects']);

        return $team->projects->sortBy('name');
    }

    public function getServersProperty()
    {
        return Server::ownedByCurrentTeamCached()->sortBy('name');
    }

    public function render()
    {
        return view('livewire.deployments.index');
    }
}
