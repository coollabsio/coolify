<?php

namespace App\Http\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;

class Modal extends Component
{
    public Server $server;

    public function proxyStatusUpdated()
    {
        $this->server->proxy->set('status', 'running');
        $this->server->save();
        $this->emit('proxyStatusUpdated');
    }
}
