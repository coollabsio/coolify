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
    public Server $server;

    public ProxyTypes $selectedProxy = ProxyTypes::TRAEFIK_V2;
    public $proxy_settings = null;
    public string|null $redirect_url = null;

    protected $listeners = ['serverValidated', 'saveConfiguration'];
    public function mount()
    {
        $this->redirect_url = $this->server->proxy->redirect_url;
    }
    public function serverValidated()
    {
        $this->server->refresh();
    }
    public function switchProxy()
    {
        $this->server->proxy->type = null;
        $this->server->save();
    }
    public function installProxy()
    {
        if (
            $this->server->proxy->last_applied_settings &&
            $this->server->proxy->last_saved_settings !== $this->server->proxy->last_applied_settings
        ) {
            $this->saveConfiguration($this->server);
        }
        $activity = resolve(InstallProxy::class)($this->server);
        $this->emit('newMonitorActivity', $activity->id);
    }

    public function setProxy(string $proxy_type)
    {
        $this->server->proxy->type = $proxy_type;
        $this->server->proxy->status = 'exited';
        $this->server->save();
    }
    public function stopProxy()
    {
        instant_remote_process([
            "docker rm -f coolify-proxy",
        ], $this->server);
        $this->server->proxy->status = 'exited';
        $this->server->save();
    }
    public function saveConfiguration()
    {
        try {
            $proxy_path = config('coolify.proxy_config_path');
            $this->proxy_settings = Str::of($this->proxy_settings)->trim()->value;
            $docker_compose_yml_base64 = base64_encode($this->proxy_settings);
            $this->server->proxy->last_saved_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
            $this->server->proxy->redirect_url = $this->redirect_url;
            $this->server->save();
            instant_remote_process([
                "echo '$docker_compose_yml_base64' | base64 -d > $proxy_path/docker-compose.yml",
            ], $this->server);
            $this->server->refresh();
            setup_default_redirect_404(redirect_url: $this->server->proxy->redirect_url, server: $this->server);
            $this->emit('success', 'Proxy configuration saved.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e);
        }
    }
    public function resetProxy()
    {
        try {
            $this->proxy_settings = resolve(CheckProxySettingsInSync::class)($this->server, true);
        } catch (\Exception $e) {
            return general_error_handler(err: $e);
        }
    }
    public function checkProxySettingsInSync()
    {
        try {
            $this->proxy_settings = resolve(CheckProxySettingsInSync::class)($this->server);
        } catch (\Exception $e) {
            return general_error_handler(err: $e);
        }
    }
}
