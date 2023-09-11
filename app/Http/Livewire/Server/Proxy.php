<?php

namespace App\Http\Livewire\Server;

use App\Actions\Proxy\CheckConfigurationSync;
use App\Actions\Proxy\SaveConfigurationSync;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Livewire\Component;

class Proxy extends Component
{
    public Server $server;

    public ?string $selectedProxy = null;
    public $proxy_settings = null;
    public ?string $redirect_url = null;

    protected $listeners = ['proxyStatusUpdated', 'saveConfiguration' => 'submit'];

    public function mount()
    {
        $this->selectedProxy = data_get($this->server, 'proxy.type');
        $this->redirect_url = data_get($this->server, 'proxy.redirect_url');
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

    public function select_proxy($proxy_type)
    {
        $this->server->proxy->type = $proxy_type;
        $this->server->proxy->status = 'exited';
        $this->server->save();
        $this->selectedProxy = $this->server->proxy->type;
        $this->emit('proxyStatusUpdated');
    }

    public function submit()
    {
        try {
            resolve(SaveConfigurationSync::class)($this->server, $this->proxy_settings);

            $this->server->proxy->redirect_url = $this->redirect_url;
            $this->server->save();

            setup_default_redirect_404(redirect_url: $this->server->proxy->redirect_url, server: $this->server);
            $this->emit('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return general_error_handler(err: $e);
        }
    }

    public function reset_proxy_configuration()
    {
        try {
            $this->proxy_settings = resolve(CheckConfigurationSync::class)($this->server, true);
        } catch (\Throwable $e) {
            return general_error_handler(err: $e);
        }
    }

    public function loadProxyConfiguration()
    {
        try {
            ray('loadProxyConfiguration');
            $this->proxy_settings = resolve(CheckConfigurationSync::class)($this->server);
        } catch (\Throwable $e) {
            return general_error_handler(err: $e);
        }
    }
}
