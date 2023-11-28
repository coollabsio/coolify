<?php

namespace App\Http\Livewire;

use App\Models\Project;
use App\Models\Server;
use Livewire\Component;

class Dashboard extends Component
{
    public $projects = [];
    public $servers = [];

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeam()->get();
        ray($this->servers[1]);
        ray($this->servers[1]->standaloneDockers);
        $this->projects = Project::ownedByCurrentTeam()->get();
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
