<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url]
    public ?string $status = null;

    #[Url]
    public ?int $server_id = null;

    #[Url]
    public ?int $project_id = null;

    #[Url]
    public ?int $application_id = null;

    public int $skip = 0;

    public int $defaultTake = 10;

    public bool $showNext = false;

    public bool $showPrev = false;

    public int $currentPage = 1;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationDeploymentQueued" => '$refresh',
        ];
    }

    public function mount()
    {
        $this->loadDeployments();
    }

    #[Computed]
    public function servers(): Collection
    {
        return Server::ownedByCurrentTeamCached();
    }

    #[Computed]
    public function projects(): Collection
    {
        return Project::ownedByCurrentTeamCached();
    }

    #[Computed]
    public function applications(): Collection
    {
        $query = Application::query()
            ->whereHas('environment.project', function ($q) {
                $q->where('team_id', currentTeam()->id);
            });

        if ($this->project_id) {
            $query->whereHas('environment', function ($q) {
                $q->where('project_id', $this->project_id);
            });
        }

        return $query->orderBy('name')->get();
    }

    #[Computed]
    public function statuses(): array
    {
        return [
            '' => 'All Statuses',
            ApplicationDeploymentStatus::QUEUED->value => 'Queued',
            ApplicationDeploymentStatus::IN_PROGRESS->value => 'In Progress',
            ApplicationDeploymentStatus::FINISHED->value => 'Finished',
            ApplicationDeploymentStatus::FAILED->value => 'Failed',
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => 'Cancelled',
        ];
    }

    #[Computed]
    public function deployments(): Collection
    {
        $serverIds = $this->servers->pluck('id');

        $query = ApplicationDeploymentQueue::with(['application.environment.project', 'application.destination.server'])
            ->whereIn('server_id', $serverIds)
            ->orderBy('created_at', 'desc');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->server_id) {
            $query->where('server_id', $this->server_id);
        }

        if ($this->project_id) {
            $query->whereHas('application.environment.project', function ($q) {
                $q->where('id', $this->project_id);
            });
        }

        if ($this->application_id) {
            $query->where('application_id', $this->application_id);
        }

        return $query->skip($this->skip)->take($this->defaultTake)->get();
    }

    #[Computed]
    public function deploymentsCount(): int
    {
        $serverIds = $this->servers->pluck('id');

        $query = ApplicationDeploymentQueue::whereIn('server_id', $serverIds);

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->server_id) {
            $query->where('server_id', $this->server_id);
        }

        if ($this->project_id) {
            $query->whereHas('application.environment.project', function ($q) {
                $q->where('id', $this->project_id);
            });
        }

        if ($this->application_id) {
            $query->where('application_id', $this->application_id);
        }

        return $query->count();
    }

    public function loadDeployments()
    {
        $this->showNext = $this->deployments->count() >= $this->defaultTake && $this->deploymentsCount > ($this->skip + $this->defaultTake);
        $this->showPrev = $this->skip > 0;
    }

    public function updatedStatus()
    {
        $this->resetPagination();
    }

    public function updatedServerId()
    {
        $this->resetPagination();
    }

    public function updatedProjectId()
    {
        $this->application_id = null;
        $this->resetPagination();
    }

    public function updatedApplicationId()
    {
        $this->resetPagination();
    }

    public function resetFilters()
    {
        $this->status = null;
        $this->server_id = null;
        $this->project_id = null;
        $this->application_id = null;
        $this->resetPagination();
    }

    private function resetPagination()
    {
        $this->skip = 0;
        $this->showPrev = false;
        $this->currentPage = 1;
        $this->loadDeployments();
    }

    public function previousPage()
    {
        $this->skip = max(0, $this->skip - $this->defaultTake);
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function nextPage()
    {
        $this->skip += $this->defaultTake;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    private function updateCurrentPage()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
    }

    public function getDeploymentUrl($deployment): ?string
    {
        $application = $deployment->application;
        if (! $application) {
            return null;
        }

        $environment = $application->environment;
        if (! $environment) {
            return null;
        }

        $project = $environment->project;
        if (! $project) {
            return null;
        }

        return route('project.application.deployment.show', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
            'deployment_uuid' => $deployment->deployment_uuid,
        ]);
    }

    public function getApplicationUrl($deployment): ?string
    {
        $application = $deployment->application;
        if (! $application) {
            return null;
        }

        $environment = $application->environment;
        if (! $environment) {
            return null;
        }

        $project = $environment->project;
        if (! $project) {
            return null;
        }

        return route('project.application.configuration', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]);
    }

    public function render()
    {
        $this->loadDeployments();

        return view('livewire.deployments.index');
    }
}
