<?php

namespace App\Livewire\Deployments;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\Source;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public ?Collection $deployments;
    public int $deployments_count = 0;
    public int $skip = 0;
    public int $defaultTake = 20;
    public bool $showNext = false;
    public bool $showPrev = false;
    public int $currentPage = 1;
    
    // Filters
    public ?string $project_filter = null;
    public ?string $server_filter = null;
    public ?string $source_filter = null;
    public ?string $status_filter = null;
    
    // Filter options
    public ?Collection $projects;
    public ?Collection $servers;
    public ?Collection $sources;
    
    protected $queryString = [
        'project_filter' => ['as' => 'project'],
        'server_filter' => ['as' => 'server'],
        'source_filter' => ['as' => 'source'],
        'status_filter' => ['as' => 'status'],
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
        $team = auth()->user()->currentTeam();
        
        // Load filter options
        $this->projects = $team->projects()->with(['environments.applications'])->get();
        $this->servers = $team->servers;
        $this->sources = $team->sources;
        
        $this->loadDeployments();
    }

    public function loadDeployments()
    {
        $query = ApplicationDeploymentQueue::query();
        
        // Get all application IDs for the current team
        $team = auth()->user()->currentTeam();
        $applicationIds = [];
        
        foreach ($team->projects as $project) {
            foreach ($project->environments as $environment) {
                foreach ($environment->applications as $application) {
                    $applicationIds[] = $application->id;
                }
            }
        }
        
        $query->whereIn('application_id', $applicationIds);
        
        // Apply filters
        if ($this->project_filter) {
            $project = $this->projects->firstWhere('uuid', $this->project_filter);
            if ($project) {
                $applicationIds = [];
                foreach ($project->environments as $environment) {
                    foreach ($environment->applications as $application) {
                        $applicationIds[] = $application->id;
                    }
                }
                $query->whereIn('application_id', $applicationIds);
            }
        }
        
        if ($this->server_filter) {
            $query->where('server_id', $this->server_filter);
        }
        
        if ($this->source_filter) {
            $query->where('source_id', $this->source_filter);
        }
        
        if ($this->status_filter) {
            $query->where('status', $this->status_filter);
        }
        
        $count = $query->count();
        $deployments = $query->orderBy('created_at', 'desc')
            ->skip($this->skip)
            ->take($this->defaultTake)
            ->get();
        
        // Load relationships
        $deployments->load(['application.destination.server', 'application.source']);
        
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->updatePagination();
    }

    public function previousPage()
    {
        $this->skip = max(0, $this->skip - $this->defaultTake);
        $this->updatePagination();
        $this->loadDeployments();
    }

    public function nextPage()
    {
        $this->skip = $this->skip + $this->defaultTake;
        $this->updatePagination();
        $this->loadDeployments();
    }

    private function updatePagination()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
        $this->showPrev = $this->skip > 0;
        $this->showNext = ($this->skip + $this->defaultTake) < $this->deployments_count;
    }

    public function clearFilters()
    {
        $this->project_filter = null;
        $this->server_filter = null;
        $this->source_filter = null;
        $this->status_filter = null;
        $this->skip = 0;
        $this->updatePagination();
        $this->loadDeployments();
    }

    public function updatingProjectFilter()
    {
        $this->skip = 0;
        $this->updatePagination();
        $this->loadDeployments();
    }

    public function updatingServerFilter()
    {
        $this->skip = 0;
        $this->updatePagination();
        $this->loadDeployments();
    }

    public function updatingSourceFilter()
    {
        $this->skip = 0;
        $this->updatePagination();
        $this->loadDeployments();
    }

    public function updatingStatusFilter()
    {
        $this->skip = 0;
        $this->updatePagination();
        $this->loadDeployments();
    }

    public function reloadDeployments()
    {
        $this->loadDeployments();
    }

    public function render()
    {
        return view('livewire.deployments.index');
    }
}
