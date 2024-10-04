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

    public $search = '';

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeam()->get();
        $this->servers = Server::ownedByCurrentTeam()->count();
    }

    public function render()
    {
        if ($this->search !== '') {
            $this->projects = Project::ownedByCurrentTeam($this->search)->get();
        } else {
            $this->projects = Project::ownedByCurrentTeam()->get();
        }

        return view('livewire.project.index');
    }
}
