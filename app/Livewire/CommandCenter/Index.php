<?php

namespace App\Livewire\CommandCenter;

use App\Models\Server;
use Livewire\Component;

class Index extends Component
{
    public $servers = [];

    public function mount()
    {
        $this->servers = Server::isReachable()->get();
    }

    public function render()
    {
        return view('livewire.command-center.index');
    }
}
