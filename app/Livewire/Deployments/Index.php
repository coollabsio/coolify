<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Deployments | Coolify')]
class Index extends Component
{
    #[Url]
    public ?string $status = null;

    #[Url]
    public ?string $server = null;

    #[Url]
    public ?string $project = null;

    #[Url]
    public ?string $source = null;

    public int $skip = 0;

    public int $defaultTake = 20;

    public bool $showNext = false;

    public bool $showPrev = false;

    public int $currentPage = 1;

    public int $deploymentsCount = 0;

    public ?Collection $deployments = null;

    #[Locked]
    public array $availableServers = [];

    #[Locked]
    public array $availableProjects = [];

    #[Locked]
    public array $availableSources = [];

    #[Locked]
    public array $availableStatuses = [];

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
        ];
    }

    public function mount()
    {
        $this->loadFilterOptions();
        $this->loadDeployments();
    }

    private function loadFilterOptions()
    {
        $teamId = currentTeam()->id;
        $servers = Server::ownedByCurrentTeamCached();
        $serverIds = $servers->pluck('id');

        // Available servers
        $serversWithDeployments = ApplicationDeploymentQueue::whereIn('server_id', $serverIds)
            ->select('server_id', 'server_name')
            ->distinct()
            ->get();

        $this->availableServers = $serversWithDeployments
            ->mapWithKeys(fn ($d) => [$d->server_id => $d->server_name ?: "Server #{$d->server_id}"])
            ->toArray();

        // Available projects
        $projects = Project::ownedByCurrentTeam()->with('environments.applications')->get();
        $this->availableProjects = $projects->mapWithKeys(fn ($p) => [$p->uuid => $p->name])->toArray();

        // Available sources - collect from applications that have deployments
        $applicationIds = ApplicationDeploymentQueue::whereIn('server_id', $serverIds)
            ->select('application_id')
            ->distinct()
            ->pluck('application_id');

        $sources = \App\Models\Application::whereIn('id', $applicationIds)
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->select('source_type', 'source_id')
            ->distinct()
            ->get();

        $sourceOptions = [];
        foreach ($sources as $s) {
            $sourceModel = app($s->source_type)->find($s->source_id);
            if ($sourceModel) {
                $key = $s->source_type . ':' . $s->source_id;
                $sourceOptions[$key] = $sourceModel->name ?? class_basename($s->source_type) . " #{$s->source_id}";
            }
        }
        $this->availableSources = $sourceOptions;

        // Available statuses
        $this->availableStatuses = collect(ApplicationDeploymentStatus::cases())
            ->mapWithKeys(fn ($s) => [$s->value => match ($s) {
                ApplicationDeploymentStatus::QUEUED => 'Queued',
                ApplicationDeploymentStatus::IN_PROGRESS => 'In Progress',
                ApplicationDeploymentStatus::FINISHED => 'Finished',
                ApplicationDeploymentStatus::FAILED => 'Failed',
                ApplicationDeploymentStatus::CANCELLED_BY_USER => 'Cancelled',
            }])
            ->toArray();
    }

    public function loadDeployments()
    {
        $servers = Server::ownedByCurrentTeamCached();
        $serverIds = $servers->pluck('id');

        $query = ApplicationDeploymentQueue::whereIn('server_id', $serverIds)
            ->orderBy('created_at', 'desc');

        // Apply status filter
        if ($this->status) {
            $query->where('status', $this->status);
        }

        // Apply server filter
        if ($this->server) {
            $query->where('server_id', $this->server);
        }

        // Apply project filter
        if ($this->project) {
            $projectModel = Project::ownedByCurrentTeam()->where('uuid', $this->project)->first();
            if ($projectModel) {
                $applicationIds = $projectModel->environments()
                    ->with('applications')
                    ->get()
                    ->flatMap(fn ($env) => $env->applications->pluck('id'));
                $query->whereIn('application_id', $applicationIds);
            }
        }

        // Apply source filter
        if ($this->source && str_contains($this->source, ':')) {
            [$sourceType, $sourceId] = explode(':', $this->source, 2);
            $applicationIds = \App\Models\Application::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->pluck('id');
            $query->whereIn('application_id', $applicationIds);
        }

        $this->deploymentsCount = $query->count();
        $this->deployments = $query->skip($this->skip)
            ->take($this->defaultTake)
            ->get();

        $this->showNext = $this->deployments->count() >= $this->defaultTake;
    }

    public function reloadDeployments()
    {
        $this->loadFilterOptions();
        $this->loadDeployments();
    }

    public function updatedStatus()
    {
        $this->resetPagination();
        $this->loadDeployments();
    }

    public function updatedServer()
    {
        $this->resetPagination();
        $this->loadDeployments();
    }

    public function updatedProject()
    {
        $this->resetPagination();
        $this->loadDeployments();
    }

    public function updatedSource()
    {
        $this->resetPagination();
        $this->loadDeployments();
    }

    public function clearFilters()
    {
        $this->status = null;
        $this->server = null;
        $this->project = null;
        $this->source = null;
        $this->resetPagination();
        $this->loadDeployments();
    }

    public function previousPage()
    {
        $this->skip = max(0, $this->skip - $this->defaultTake);
        $this->showPrev = $this->skip > 0;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function nextPage()
    {
        $this->skip += $this->defaultTake;
        $this->showPrev = true;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    private function resetPagination()
    {
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
    }

    private function updateCurrentPage()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
    }

    private function hasActiveFilters(): bool
    {
        return $this->status || $this->server || $this->project || $this->source;
    }

    public function render()
    {
        return view('livewire.deployments.index', [
            'hasActiveFilters' => $this->hasActiveFilters(),
            'totalPages' => $this->deploymentsCount > 0 ? ceil($this->deploymentsCount / $this->defaultTake) : 1,
        ]);
    }
}
