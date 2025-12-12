<?php

namespace App\Livewire\Deployment;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public ?string $selectedProjectId = null;

    public ?int $selectedServerId = null;

    public ?int $selectedSourceId = null;

    public ?string $selectedSourceType = null;

    public ?string $selectedStatus = null;

    public int $perPage = 20;

    protected $queryString = [
        'selectedProjectId' => ['except' => ''],
        'selectedServerId' => ['except' => ''],
        'selectedSourceId' => ['except' => ''],
        'selectedSourceType' => ['except' => ''],
        'selectedStatus' => ['except' => ''],
    ];

    public function mount()
    {
        // Initialize filters from query string if present
    }

    public function loadDeployments(): LengthAwarePaginator
    {
        $teamId = currentTeam()->id;

        // Use explicit joins with type casting because application_deployment_queues.application_id
        // is stored as a string (VARCHAR) but applications.id is a bigint, causing PostgreSQL
        // type mismatch errors when using whereHas relationships
        $query = ApplicationDeploymentQueue::query()
            ->join('applications', function ($join) {
                $join->on(DB::raw('CAST(application_deployment_queues.application_id AS INTEGER)'), '=', 'applications.id');
            })
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId)
            ->whereNull('applications.deleted_at')
            ->select('application_deployment_queues.*');

        // Filter by project
        if ($this->selectedProjectId) {
            $query->where('projects.uuid', $this->selectedProjectId);
        }

        // Filter by server
        if ($this->selectedServerId) {
            $query->where('application_deployment_queues.server_id', $this->selectedServerId);
        }

        // Filter by source
        if ($this->selectedSourceId && $this->selectedSourceType) {
            $query->where('applications.source_id', $this->selectedSourceId)
                ->where('applications.source_type', $this->selectedSourceType);
        }

        // Filter by status
        if ($this->selectedStatus) {
            $query->where('application_deployment_queues.status', $this->selectedStatus);
        }

        return $query->with([
                'application.environment.project',
                'application.source',
            ])
            ->orderBy('application_deployment_queues.created_at', 'desc')
            ->paginate($this->perPage);
    }

    public function updatedSelectedProjectId()
    {
        $this->resetPage();
    }

    public function updatedSelectedServerId()
    {
        $this->resetPage();
    }

    public function updatedSelectedSourceId()
    {
        $this->resetPage();
    }

    public function updatedSelectedStatus()
    {
        $this->resetPage();
    }

    public function clearFilters()
    {
        $this->selectedProjectId = null;
        $this->selectedServerId = null;
        $this->selectedSourceId = null;
        $this->selectedSourceType = null;
        $this->selectedStatus = null;
        $this->resetPage();
    }

    public function getFilterOptionsProperty(): array
    {
        $teamId = currentTeam()->id;

        // Get projects available for filtering
        $projects = Project::ownedByCurrentTeamCached()
            ->pluck('name', 'uuid')
            ->toArray();

        // Get servers that have deployments (only show servers with actual deployments)
        // Uses same join pattern as loadDeployments() to avoid type mismatch
        $serverIds = ApplicationDeploymentQueue::query()
            ->join('applications', function ($join) {
                $join->on(DB::raw('CAST(application_deployment_queues.application_id AS INTEGER)'), '=', 'applications.id');
            })
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId)
            ->whereNull('applications.deleted_at')
            ->distinct('application_deployment_queues.server_id')
            ->whereNotNull('application_deployment_queues.server_id')
            ->pluck('application_deployment_queues.server_id')
            ->toArray();

        $servers = [];
        if (! empty($serverIds)) {
            $servers = Server::whereIn('id', $serverIds)
                ->pluck('name', 'id')
                ->toArray();
        }

        // Get sources (GitHub/GitLab apps) from applications with deployments
        // Applications use polymorphic relationships for sources, so we need to handle
        // both GithubApp and GitlabApp types
        $sources = [];
        $sourceData = ApplicationDeploymentQueue::query()
            ->join('applications', function ($join) {
                $join->on(DB::raw('CAST(application_deployment_queues.application_id AS INTEGER)'), '=', 'applications.id');
            })
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId)
            ->whereNull('applications.deleted_at')
            ->whereNotNull('applications.source_id')
            ->whereNotNull('applications.source_type')
            ->select('applications.source_id', 'applications.source_type')
            ->distinct()
            ->get()
            ->map(function ($row) {
                // Resolve polymorphic source relationship
                $source = null;
                if ($row->source_type === GithubApp::class) {
                    $source = GithubApp::find($row->source_id);
                } elseif ($row->source_type === GitlabApp::class) {
                    $source = GitlabApp::find($row->source_id);
                }

                if (! $source) {
                    return null;
                }

                return [
                    'id' => $source->id,
                    'type' => $row->source_type,
                    'source' => $source,
                ];
            })
            ->filter()
            // Ensure unique sources by combining type and id
            ->unique(function ($item) {
                return $item['type'].'-'.$item['id'];
            });

        foreach ($sourceData as $item) {
            $source = $item['source'];
            if ($item['type'] === GithubApp::class) {
                $sources[] = [
                    'id' => $source->id,
                    'type' => GithubApp::class,
                    'name' => 'GitHub: '.$source->name,
                ];
            } elseif ($item['type'] === GitlabApp::class) {
                $sources[] = [
                    'id' => $source->id,
                    'type' => GitlabApp::class,
                    'name' => 'GitLab: '.$source->name,
                ];
            }
        }

        // Get statuses
        $statuses = [];
        foreach (ApplicationDeploymentStatus::cases() as $status) {
            $statuses[$status->value] = match ($status) {
                ApplicationDeploymentStatus::QUEUED => 'Queued',
                ApplicationDeploymentStatus::IN_PROGRESS => 'In Progress',
                ApplicationDeploymentStatus::FINISHED => 'Finished',
                ApplicationDeploymentStatus::FAILED => 'Failed',
                ApplicationDeploymentStatus::CANCELLED_BY_USER => 'Cancelled',
            };
        }

        return [
            'projects' => $projects,
            'servers' => $servers,
            'sources' => $sources,
            'statuses' => $statuses,
        ];
    }

    public function getShouldShowProjectFilterProperty(): bool
    {
        return count($this->getFilterOptionsProperty()['projects']) > 1;
    }

    public function getShouldShowServerFilterProperty(): bool
    {
        return count($this->getFilterOptionsProperty()['servers']) > 1;
    }

    public function getShouldShowSourceFilterProperty(): bool
    {
        return count($this->getFilterOptionsProperty()['sources']) > 1;
    }

    public function reloadDeployments()
    {
        // This method is called by wire:poll to refresh the data
        $this->render();
    }

    public function previousPage()
    {
        $this->setPage(max(1, $this->getPage() - 1));
    }

    public function nextPage()
    {
        $deployments = $this->loadDeployments();
        if ($deployments->hasMorePages()) {
            $this->setPage($this->getPage() + 1);
        }
    }

    /**
     * Determine if we should poll for updates.
     * Checks for ANY active deployments across the team, not just the current page,
     * to ensure new deployments are detected even if they're not visible yet.
     */
    public function getIsPollingProperty(): bool
    {
        $teamId = currentTeam()->id;

        // Check if there are ANY active deployments in the team (not just current page)
        // This ensures new deployments are detected even if they're not on the current page
        $hasActiveDeployments = ApplicationDeploymentQueue::query()
            ->join('applications', function ($join) {
                $join->on(DB::raw('CAST(application_deployment_queues.application_id AS INTEGER)'), '=', 'applications.id');
            })
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId)
            ->whereNull('applications.deleted_at')
            ->whereIn('application_deployment_queues.status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->exists();

        return $hasActiveDeployments;
    }

    /**
     * Get formatted log lines for a deployment.
     * Only returns logs for active deployments (in_progress or queued).
     * Reuses the same log decoding logic from the deployment show page.
     */
    public function getLogLines(ApplicationDeploymentQueue $deployment): Collection
    {
        // Only show logs for active deployments to avoid unnecessary processing
        if (!in_array($deployment->status, ['in_progress', 'queued'])) {
            return collect();
        }

        // Decode and format logs using the same helper function as deployment show page
        return decode_remote_command_output($deployment)->map(function ($logLine) {
            // Escape HTML and convert URLs to clickable links
            $logLine['line'] = e($logLine['line']);
            $logLine['line'] = preg_replace(
                '/(https?:\/\/[^\s]+)/',
                '<a href="$1" target="_blank" rel="noopener noreferrer" class="underline text-neutral-400">$1</a>',
                $logLine['line'],
            );

            return $logLine;
        });
    }

    public function render()
    {
        $deployments = $this->loadDeployments();

        return view('livewire.deployment.index', [
            'deployments' => $deployments,
            'filterOptions' => $this->getFilterOptionsProperty(),
            'isPolling' => $this->isPolling,
            'shouldShowProjectFilter' => $this->shouldShowProjectFilter,
            'shouldShowServerFilter' => $this->shouldShowServerFilter,
            'shouldShowSourceFilter' => $this->shouldShowSourceFilter,
        ]);
    }
}

