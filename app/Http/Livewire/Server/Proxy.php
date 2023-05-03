<?php

namespace App\Http\Livewire\Server;

use App\Actions\Proxy\InstallProxy;
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
        $activity = resolve(InstallProxy::class)($this->server);

        $this->emit('newMonitorActivity', $activity->id);
    }

    public function render()
    {
        return view('livewire.server.proxy');
    }
}
