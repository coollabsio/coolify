<?php

namespace App\Livewire\Project;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Livewire\Component;

class Index extends Component
{
    public $projects;

    public $servers;

    public $private_keys;

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeam()->get();
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->servers = Server::ownedByCurrentTeam()->count();
    }

    public function render()
    {
        return view('livewire.project.index');
    }
}
