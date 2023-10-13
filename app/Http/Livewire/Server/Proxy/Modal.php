<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class Modal extends Component
{
    public Server $server;

    public function proxyStatusUpdated()
    {
        $this->emit('proxyStatusUpdated');
    }
}
