<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckConfiguration;
use App\Actions\Proxy\SaveConfiguration;
use App\Actions\Proxy\StartProxy;
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
        $this->selectedProxy = $this->server->proxyType();
        $this->redirect_url = data_get($this->server, 'proxy.redirect_url');
    }

    public function proxyStatusUpdated()
    {
        $this->dispatch('refresh')->self();
    }

    public function change_proxy()
    {
        $this->server->proxy = null;
        $this->server->save();
    }

    public function select_proxy($proxy_type)
    {
        $this->server->proxy->set('status', 'exited');
        $this->server->proxy->set('type', $proxy_type);
        $this->server->save();
        $this->selectedProxy = $this->server->proxy->type;
        if ($this->selectedProxy !== 'NONE') {
            StartProxy::run($this->server, false);
        }
        $this->dispatch('proxyStatusUpdated');
    }

    public function submit()
    {
        try {
            SaveConfiguration::run($this->server, $this->proxy_settings);
            $this->server->proxy->redirect_url = $this->redirect_url;
            $this->server->save();
            $this->server->setupDefault404Redirect();
            $this->dispatch('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function reset_proxy_configuration()
    {
        try {
            $this->proxy_settings = CheckConfiguration::run($this->server, true);
            SaveConfiguration::run($this->server, $this->proxy_settings);
            $this->server->save();
            $this->dispatch('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadProxyConfiguration()
    {
        try {
            $this->proxy_settings = CheckConfiguration::run($this->server);
            if (str($this->proxy_settings)->contains('--api.dashboard=true') && str($this->proxy_settings)->contains('--api.insecure=true')) {
                $this->dispatch('traefikDashboardAvailable', true);
            } else {
                $this->dispatch('traefikDashboardAvailable', false);
            }

        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
