<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class Dashboard extends Component
{
    public $projects = [];
    public Collection $servers;
    public $deployments_per_server;
    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeam()->get();
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->get_deployments();
    }
    public function cleanup_queue()
    {
        $this->dispatch('success', 'Cleanup started.');
        Artisan::queue('app:init', [
            '--cleanup-deployments' => 'true'
        ]);
    }
    public function get_deployments()
    {
        $this->deployments_per_server = ApplicationDeploymentQueue::whereIn("status", ["in_progress", "queued"])->whereIn("server_id", $this->servers->pluck("id"))->get([
            "id",
            "application_id",
            "application_name",
            "deployment_url",
            "pull_request_id",
            "server_name",
            "server_id",
            "status"
        ])->sortBy('id')->groupBy('server_name')->toArray();
    }
    // public function getIptables()
    // {
    //     $servers = Server::ownedByCurrentTeam()->get();
    //     foreach ($servers as $server) {
    //         checkRequiredCommands($server);
    //         $iptables = instant_remote_process(['docker run --privileged --net=host --pid=host --ipc=host --volume /:/host busybox chroot /host bash -c "iptables -L -n | jc --iptables"'], $server);
    //         ray($iptables);
    //     }
    // }
    public function render()
    {
        return view('livewire.dashboard');
    }
}
