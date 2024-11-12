<?php

namespace App\Livewire\Destination;

use App\Models\Server;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    #[Locked]
    public $servers;

    public function mount()
    {
        $this->servers = Server::isUsable()->get();
    }

    public function render()
    {
        return view('livewire.destination.index');
    }
}
