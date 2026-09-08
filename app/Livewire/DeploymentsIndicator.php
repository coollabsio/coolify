<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DeploymentsIndicator extends Component
{
    public bool $expanded = false;

    /**
     * Persisted across polls. Livewire update requests are not the page route,
     * so this must not be re-derived from request()->routeIs() on every render.
     */
    public bool $shouldShow = true;

    public function mount(): void
    {
        $this->shouldShow = $this->shouldShowForCurrentRequest();
    }

    public function updateShouldShowFromPath(string $path): void
    {
        $this->shouldShow = ! $this->isDashboardPath($path);
    }

    #[Computed]
    public function deployments()
    {
        $servers = Server::ownedByCurrentTeamCached();

        return ApplicationDeploymentQueue::with(['application.environment.project'])
            ->whereIn('status', ['in_progress', 'queued'])
            ->whereIn('server_id', $servers->pluck('id'))
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

    public function toggleExpanded()
    {
        $this->expanded = ! $this->expanded;
    }

    public function render()
    {
        return view('livewire.deployments-indicator');
    }

    private function shouldShowForCurrentRequest(): bool
    {
        if (request()->routeIs('dashboard')) {
            return false;
        }

        return ! $this->isDashboardPath(request()->path());
    }

    private function isDashboardPath(string $path): bool
    {
        $normalized = trim($path, '/');

        return $normalized === '' || $normalized === '/';
    }
}
