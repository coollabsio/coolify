<?php

namespace App\Livewire\Dashboard;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class ActiveDeployments extends Component
{
    public Collection $deployments;

    public function mount(): void
    {
        $this->refreshDeployments();
    }

    public function refreshDeployments(): void
    {
        $serverIds = Server::ownedByCurrentTeamCached()->pluck('id');

        $this->deployments = ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->whereIn('status', ['in_progress', 'queued'])
            ->whereIn('server_id', $serverIds)
            ->orderBy('status')
            ->orderBy('id')
            ->limit(8)
            ->get([
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
            ]);
    }

    public function render()
    {
        return view('livewire.dashboard.active-deployments');
    }
}
