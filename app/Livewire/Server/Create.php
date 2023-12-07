<?php

namespace App\Livewire\Server;

use App\Models\PrivateKey;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];
    public bool $limit_reached = false;
    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeam()->get();
        if (!isCloud()) {
            $this->limit_reached = false;
            return;
        }
        $team = currentTeam();
        $servers = $team->servers->count();
        ['serverLimit' => $serverLimit] = $team->limits;

        $this->limit_reached = $servers >= $serverLimit;
    }
    public function render()
    {
        return view('livewire.server.create');
    }
}
