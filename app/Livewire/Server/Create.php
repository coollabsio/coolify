<?php

namespace App\Livewire\Server;

use App\Models\PrivateKey;
use App\Models\Team;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];

    public bool $limit_reached = false;

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeam()->get();
        if (! isCloud()) {
            $this->limit_reached = false;

            return;
        }
        $this->limit_reached = Team::serverLimitReached();
    }

    public function render()
    {
        return view('livewire.server.create');
    }
}
