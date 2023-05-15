<?php

namespace App\Http\Livewire\Server;

use App\Actions\Proxy\CheckProxySettingsInSync;
use App\Actions\Proxy\InstallProxy;
use App\Enums\ProxyTypes;
use Illuminate\Support\Str;
use App\Models\Server;
use Livewire\Component;

class Proxy extends Component
{
    protected $listeners = ['serverValidated'];
    public Server $server;

    public ProxyTypes $selectedProxy = ProxyTypes::TRAEFIK_V2;
    public $proxy_settings = null;

    public function serverValidated()
    {
        $this->server->settings->refresh();
    }
    public function installProxy()
    {
        $this->saveConfiguration($this->server);
        $activity = resolve(InstallProxy::class)($this->server);
        $this->emit('newMonitorActivity', $activity->id);
    }

    public function proxyStatus()
    {
        $this->server->extra_attributes->proxy_status = checkContainerStatus(server: $this->server, container_id: 'coolify-proxy');
        $this->server->save();
    }
    public function setProxy()
    {
        $this->server->extra_attributes->proxy_type = $this->selectedProxy->value;
        $this->server->extra_attributes->proxy_status = 'exited';
        $this->server->save();
    }
    public function stopProxy()
    {
        instantRemoteProcess([
            "docker rm -f coolify-proxy",
        ], $this->server);
        $this->server->extra_attributes->proxy_status = 'exited';
        $this->server->save();
    }
    public function saveConfiguration()
    {
        try {
            $proxy_path = config('coolify.proxy_config_path');
            $this->proxy_settings = Str::of($this->proxy_settings)->trim()->value;
            $docker_compose_yml_base64 = base64_encode($this->proxy_settings);
            $this->server->extra_attributes->last_saved_proxy_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
            $this->server->save();
            instantRemoteProcess([
                "echo '$docker_compose_yml_base64' | base64 -d > $proxy_path/docker-compose.yml",
            ], $this->server);
        } catch (\Exception $e) {
            return generalErrorHandler($e);
        }
    }
    public function checkProxySettingsInSync()
    {
        try {
            $this->proxy_settings = resolve(CheckProxySettingsInSync::class)($this->server, true);
        } catch (\Exception $e) {
            return generalErrorHandler($e);
        }
    }
}
