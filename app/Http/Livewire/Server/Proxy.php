<?php

namespace App\Http\Livewire\Server;

use App\Actions\Proxy\CheckProxySettingsInSync;
use App\Actions\Proxy\InstallProxy;
use App\Enums\ActivityTypes;
use App\Models\Server;
use Livewire\Component;

class Proxy extends Component
{
    public Server $server;

    protected string $selectedProxy = '';

    public $is_proxy_installed;

    public $is_check_proxy_complete = false;
    public $is_proxy_settings_in_sync = false;

    public function mount(Server $server)
    {
        $this->server = $server;
    }

    public function runInstallProxy()
    {
        $activity = resolve(InstallProxy::class)($this->server);

        $this->emit('newMonitorActivity', $activity->id);
    }

    public function checkProxySettingsInSync()
    {
        $this->is_proxy_settings_in_sync = resolve(CheckProxySettingsInSync::class)($this->server);

        $this->is_check_proxy_complete = true;
    }

    public function render()
    {
        return view('livewire.server.proxy');
    }
}
