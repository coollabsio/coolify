<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $project = 'all';

    public string $server = 'all';

    public string $source = 'all';

    public string $status = 'all';

    public int $perPage = 20;

    protected $queryString = [
        'project' => ['except' => 'all'],
        'server' => ['except' => 'all'],
        'source' => ['except' => 'all'],
        'status' => ['except' => 'all'],
    ];

    public function updatingProject(): void
    {
        $this->resetPage();
    }

    public function updatingServer(): void
    {
        $this->resetPage();
    }

    public function updatingSource(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->project = 'all';
        $this->server = 'all';
        $this->source = 'all';
        $this->status = 'all';
        $this->resetPage();
    }

    public function getListeners(): array
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
        ];
    }

    public function projectOptions(): Collection
    {
        return Project::ownedByCurrentTeam()
            ->whereHas('applications.deployment_queue')
            ->get(['id', 'name']);
    }

    public function serverOptions(): Collection
    {
        return Server::ownedByCurrentTeam(['id', 'name'])
            ->whereHas('settings')
            ->whereHas('teamDeployments')
            ->get(['id', 'name']);
    }

    public function sourceOptions(): Collection
    {
        return Application::ownedByCurrentTeam()
            ->whereHas('deployment_queue')
            ->with('source')
            ->get(['id', 'source_id', 'source_type', 'git_repository'])
            ->map(fn (Application $application) => [
                'value' => $this->sourceFilterValue($application->source_type, $application->source_id),
                'label' => $this->sourceFilterLabel($application),
            ])
            ->unique('value')
            ->sortBy('label')
            ->values();
    }

    public function statusOptions(): Collection
    {
        return collect(ApplicationDeploymentStatus::cases())
            ->map(fn (ApplicationDeploymentStatus $status) => [
                'value' => $status->value,
                'label' => $this->statusLabel($status->value),
            ]);
    }

    public function deployments(): LengthAwarePaginator
    {
        return $this->deploymentQuery()
            ->latest('created_at')
            ->paginate($this->perPage);
    }

    public function hasActiveFilters(): bool
    {
        return $this->project !== 'all'
            || $this->server !== 'all'
            || $this->source !== 'all'
            || $this->status !== 'all';
    }

    public function showServerFilter(): bool
    {
        return $this->serverOptions()->count() > 1;
    }

    public function showSourceFilter(): bool
    {
        return $this->sourceOptions()->count() > 1;
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            ApplicationDeploymentStatus::FINISHED->value => 'Done',
            ApplicationDeploymentStatus::IN_PROGRESS->value => 'Pending',
            ApplicationDeploymentStatus::QUEUED->value => 'Queued',
            ApplicationDeploymentStatus::FAILED->value => 'Failed',
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => 'Cancelled',
            default => str($status ?? 'unknown')->headline()->toString(),
        };
    }

    public function deploymentType(ApplicationDeploymentQueue $deployment): string
    {
        if ($deployment->is_webhook) {
            return $deployment->pull_request_id ? "Webhook PR #{$deployment->pull_request_id}" : 'Webhook';
        }

        if ($deployment->pull_request_id) {
            return "Pull Request #{$deployment->pull_request_id}";
        }

        if ($deployment->rollback) {
            return 'Rollback';
        }

        if ($deployment->is_api) {
            return 'API';
        }

        return 'Manual';
    }

    public function deploymentUrl(ApplicationDeploymentQueue $deployment): ?string
    {
        if ($deployment->deployment_url) {
            return $deployment->deployment_url;
        }

        $application = $deployment->application;
        $environment = $application?->environment;
        $project = $environment?->project;

        if (! $application || ! $environment || ! $project) {
            return null;
        }

        return route('project.application.deployment.show', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
            'deployment_uuid' => $deployment->deployment_uuid,
        ]);
    }

    public function sourceName(?Application $application): string
    {
        if (! $application) {
            return 'Unknown source';
        }

        return $this->sourceFilterLabel($application);
    }

    private function deploymentQuery(): Builder
    {
        $serverIds = Server::ownedByCurrentTeam(['id'])->pluck('id');

        return ApplicationDeploymentQueue::query()
            ->with(['application.environment.project', 'application.source'])
            ->whereIn('server_id', $serverIds)
            ->when($this->project !== 'all', function (Builder $query) {
                $query->whereHas('application.environment.project', function (Builder $query) {
                    $query->where('id', $this->project);
                });
            })
            ->when($this->server !== 'all', function (Builder $query) {
                $query->where('server_id', $this->server);
            })
            ->when($this->source !== 'all', function (Builder $query) {
                if ($this->source === 'public-git') {
                    $query->whereHas('application', function (Builder $query) {
                        $query->whereNull('source_type')->whereNull('source_id');
                    });

                    return;
                }

                $sourceParts = explode(':', $this->source, 2);

                if (count($sourceParts) !== 2) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                [$sourceType, $sourceId] = $sourceParts;

                $query->whereHas('application', function (Builder $query) use ($sourceType, $sourceId) {
                    $query->where('source_type', $sourceType)->where('source_id', $sourceId);
                });
            })
            ->when($this->status !== 'all', function (Builder $query) {
                $query->where('status', $this->status);
            });
    }

    private function sourceFilterValue(?string $sourceType, ?int $sourceId): string
    {
        if (! $sourceType || ! $sourceId) {
            return 'public-git';
        }

        return "{$sourceType}:{$sourceId}";
    }

    private function sourceFilterLabel(Application $application): string
    {
        if (! $application->source_type || ! $application->source_id) {
            return 'Public Git';
        }

        $sourceType = str(class_basename($application->source_type))->headline()->toString();
        $sourceName = $application->source?->name;

        return $sourceName ? "{$sourceName} ({$sourceType})" : $sourceType;
    }

    public function render()
    {
        return view('livewire.deployments.index', [
            'deployments' => $this->deployments(),
            'projects' => $this->projectOptions(),
            'servers' => $this->serverOptions(),
            'sources' => $this->sourceOptions(),
            'statuses' => $this->statusOptions(),
        ]);
    }
}
