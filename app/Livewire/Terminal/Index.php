<?php

namespace App\Livewire\Terminal;

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
        return view('livewire.terminal.index');
    }
}
