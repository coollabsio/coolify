<?php

namespace App\Http\Livewire\Server;

use App\Actions\Proxy\CheckProxySettingsInSync;
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

    protected $listeners = ['proxyStatusUpdated', 'saveConfiguration'];
    public function mount()
    {
        $this->redirect_url = $this->server->proxy->redirect_url;
    }
    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }
    public function change_proxy()
    {
        $this->server->proxy = null;
        $this->server->save();
        $this->emit('proxyStatusUpdated');
    }
    public function select_proxy(string $proxy_type)
    {
        $this->server->proxy->type = $proxy_type;
        $this->server->proxy->status = 'exited';
        $this->server->save();
        $this->emit('proxyStatusUpdated');
    }
    public function submit()
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
    public function reset_proxy_configuration()
    {
        try {
            $this->proxy_settings = resolve(CheckProxySettingsInSync::class)($this->server, true);
        } catch (\Exception $e) {
            return general_error_handler(err: $e);
        }
    }
    public function load_proxy_configuration()
    {
        try {
            $this->proxy_settings = resolve(CheckProxySettingsInSync::class)($this->server);
        } catch (\Exception $e) {
            return general_error_handler(err: $e);
        }
    }
}