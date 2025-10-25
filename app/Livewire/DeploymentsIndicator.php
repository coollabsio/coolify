<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DeploymentsIndicator extends Component
{
    public bool $expanded = false;

    #[Computed]
    public function deployments()
    {
        $servers = Server::ownedByCurrentTeam()->get();

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
}
