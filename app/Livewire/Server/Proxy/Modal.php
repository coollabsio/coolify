<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class Modal extends Component
{
    public Server $server;

    public function proxyStatusUpdated()
    {
        $this->dispatch('proxyStatusUpdated');
    }
}
