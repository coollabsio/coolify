<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Models\Server;
use Livewire\Component;

class Index extends Component
{
    public $projects;
    public $servers;
    public function mount() {
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->servers = Server::ownedByCurrentTeam()->count();
    }
    public function render()
    {
        return view('livewire.project.index');
    }
}
