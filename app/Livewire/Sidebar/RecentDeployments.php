<?php

namespace App\Livewire\Sidebar;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Livewire\Attributes\Computed;
use Livewire\Component;

class RecentDeployments extends Component
{
    #[Computed]
    public function deployments()
    {
        $servers = Server::ownedByCurrentTeamCached();

        return ApplicationDeploymentQueue::with(['application.environment.project'])
            ->whereIn('server_id', $servers->pluck('id'))
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get([
                'id',
                'application_id',
                'application_name',
                'deployment_url',
                'status',
                'created_at'
            ]);
    }

    public function render()
    {
        return view('livewire.sidebar.recent-deployments');
    }
}
