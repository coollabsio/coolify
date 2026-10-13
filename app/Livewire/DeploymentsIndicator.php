<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DeploymentsIndicator extends Component
{
    /**
     * Where the indicator is rendered: 'sidebar' (desktop sidebar footer) or 'mobile' (mobile top bar).
     */
    public string $variant = 'sidebar';

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

    public function render()
    {
        return view('livewire.deployments-indicator');
    }
}
