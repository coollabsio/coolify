<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DeploymentsIndicator extends Component
{
    /**
     * Where the indicator is rendered: 'topbar' (desktop top bar) or 'mobile' (mobile top bar).
     */
    public string $variant = 'topbar';

    /**
     * The application on the current page. Its heading has its own "Deploying…" indicator.
     */
    #[Locked]
    public ?string $currentApplicationUuid = null;

    public function mount(): void
    {
        $this->currentApplicationUuid = request()->route('application_uuid');
    }

    #[Computed]
    public function deployments()
    {
        return ApplicationDeploymentQueue::with(['application.environment.project'])
            ->ownedByTeam(currentTeam()->id)
            ->whereIn('status', ['in_progress', 'queued'])
            ->orderBy('id')
            ->get([
                'id',
                'application_id',
                'application_name',
                'deployment_url',
                'pull_request_id',
                'server_name',
                'server_id',
                'status',
            ]);
    }

    #[Computed]
    public function deploymentCount()
    {
        return $this->deployments->count();
    }

    /**
     * Hide the counter when it would only repeat the current page's "Deploying…" indicator.
     */
    #[Computed]
    public function isOnlyCurrentApplicationDeploying(): bool
    {
        return $this->currentApplicationUuid !== null
            && $this->deployments->every(fn ($deployment) => $deployment->application?->uuid === $this->currentApplicationUuid);
    }

    public function render()
    {
        return view('livewire.deployments-indicator');
    }
}
