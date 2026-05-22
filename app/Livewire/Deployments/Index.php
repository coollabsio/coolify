<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public ?Collection $projects = null;

    public ?Collection $servers = null;

    public ?string $projectUuid = null;

    public ?string $serverUuid = null;

    public ?string $source = null;

    public ?string $status = null;

    public int $perPage = 25;

    public function mount(): void
    {
        $this->projects = Project::whereTeamId(currentTeam()->id)->orderBy('name')->get(['id', 'uuid', 'name']);
        $this->servers = Server::ownedByCurrentTeamCached()->sortBy('name')->values();
    }

    public function updated($name): void
    {
        if (in_array($name, ['projectUuid', 'serverUuid', 'source', 'status', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    private function deploymentsQuery(bool $applySourceFilter = true): Builder
    {
        $teamId = currentTeam()->id;

        $query = ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->whereHas('application', function (Builder $query) use ($teamId) {
                $query->whereHas('environment.project', function (Builder $query) use ($teamId) {
                    $query->where('team_id', $teamId);
                });
            })
            ->orderByDesc('id');

        if ($this->projectUuid) {
            $projectId = $this->projects?->firstWhere('uuid', $this->projectUuid)?->id;
            if ($projectId) {
                $query->whereHas('application.environment', fn (Builder $q) => $q->where('project_id', $projectId));
            }
        }

        if ($this->serverUuid) {
            $serverId = $this->servers?->firstWhere('uuid', $this->serverUuid)?->id;
            if ($serverId) {
                $query->where('server_id', $serverId);
            }
        }

        if ($applySourceFilter) {
            if ($this->source === 'webhook') {
                $query->where('is_webhook', true);
            } elseif ($this->source === 'api') {
                $query->where('is_api', true);
            } elseif ($this->source === 'manual') {
                $query->where('is_webhook', false)->where('is_api', false);
            }
        }

        if ($this->status) {
            $allowedStatuses = collect(ApplicationDeploymentStatus::cases())->pluck('value')->all();
            if (in_array($this->status, $allowedStatuses, true)) {
                $query->where('status', $this->status);
            }
        }

        return $query;
    }

    public function render()
    {
        $deployments = $this->deploymentsQuery()->paginate($this->perPage);

        $queryWithoutSource = $this->deploymentsQuery(applySourceFilter: false);
        $availableSources = collect([
            'webhook' => 'Webhook',
            'api' => 'API',
            'manual' => 'Manual',
        ])->filter(function ($label, $value) use ($queryWithoutSource) {
            $query = clone $queryWithoutSource;

            return match ($value) {
                'webhook' => $query->where('is_webhook', true)->exists(),
                'api' => $query->where('is_api', true)->exists(),
                'manual' => $query->where('is_webhook', false)->where('is_api', false)->exists(),
                default => false,
            };
        });

        return view('livewire.deployments.index', [
            'deployments' => $deployments,
            'availableSources' => $availableSources,
            'availableStatuses' => collect(ApplicationDeploymentStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()]),
        ]);
    }
}
