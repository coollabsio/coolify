<?php

namespace App\Http\Livewire\Server;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Livewire\Component;

class Proxy extends Component
{
    public Server $server;

    protected string $selectedProxy = '';

    public function mount(Server $server)
    {
        $this->server = $server;
    }

    public function runInstallProxy()
    {
        $activity = remoteProcess(['ls -alh'], $this->server, ActivityTypes::INLINE->value);

        $this->emit('newMonitorActivity', $activity->id);
    }

    public function render()
    {
        return view('livewire.server.proxy');
    }
}
