<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class Dashboard extends Component
{
    public $projects = [];

    public Collection $servers;

    public Collection $private_keys;

    public $deployments_per_server;

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeam()->get();
        $this->servers = Server::ownedByCurrentTeam()->get();
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->get_deployments();
    }

    public function cleanup_queue()
    {
        $this->dispatch('success', 'Cleanup started.');
        Artisan::queue('cleanup:application-deployment-queue', [
            '--team-id' => currentTeam()->id,
        ]);
    }

    public function get_deployments()
    {
        $this->deployments_per_server = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('server_id', $this->servers->pluck('id'))->get([
            'id',
            'application_id',
            'application_name',
            'deployment_url',
            'pull_request_id',
            'server_name',
            'server_id',
            'status',
        ])->sortBy('id')->groupBy('server_name')->toArray();
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
