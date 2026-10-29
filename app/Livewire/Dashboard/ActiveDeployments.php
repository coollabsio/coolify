<?php

namespace App\Livewire\Dashboard;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The lists are computed, not public properties: Livewire would restore them with SELECT * on every poll.
 */
class ActiveDeployments extends Component
{
    private const COLUMNS = [
        'id',
        'application_id',
        'application_name',
        'deployment_url',
        'deployment_uuid',
        'pull_request_id',
        'server_name',
        'server_id',
        'status',
        'created_at',
        'finished_at',
    ];

    /**
     * Polling target. The lists are loaded again on every render.
     */
    public function refreshDeployments(): void {}

    #[Computed]
    public function activeDeployments(): Collection
    {
        return $this->deploymentsQuery()
            ->whereIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ])
            ->orderBy('status')
            ->orderBy('id')
            ->limit(5)
            ->get(self::COLUMNS);
    }

    #[Computed]
    public function recentDeployments(): Collection
    {
        return $this->deploymentsQuery()
            ->whereNotIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ])
            ->orderByDesc('id')
            ->limit(5)
            ->get(self::COLUMNS);
    }

    private function deploymentsQuery(): Builder
    {
        return ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->ownedByTeam(currentTeam()->id)
            ->visibleToCurrentActor();
    }

    public function render()
    {
        return view('livewire.dashboard.active-deployments');
    }
}
