<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckConfiguration;
use App\Actions\Proxy\SaveConfiguration;
use App\Models\Server;
use Livewire\Component;

class Proxy extends Component
{
    public Server $server;

    public ?string $selectedProxy = null;

    public $proxy_settings = null;

    public bool $redirect_enabled = true;

    public ?string $redirect_url = null;

    protected $listeners = ['proxyStatusUpdated', 'saveConfiguration' => 'submit'];

    protected $rules = [
        'server.settings.generate_exact_labels' => 'required|boolean',
    ];

    public function mount()
    {
        $this->selectedProxy = $this->server->proxyType();
        $this->redirect_enabled = data_get($this->server, 'proxy.redirect_enabled', true);
        $this->redirect_url = data_get($this->server, 'proxy.redirect_url');
    }

    public function proxyStatusUpdated()
    {
        $this->dispatch('refresh')->self();
    }

    public function changeProxy()
    {
        $this->server->proxy = null;
        $this->server->save();
        $this->dispatch('reloadWindow');
    }

    public function selectProxy($proxy_type)
    {
        try {
            $this->server->changeProxy($proxy_type, async: false);
            $this->selectedProxy = $this->server->proxy->type;
            $this->dispatch('reloadWindow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->validate();
            $this->server->settings->save();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveRedirect()
    {
        try {
            $this->server->proxy->redirect_enabled = $this->redirect_enabled;
            $this->server->save();
            $this->server->setupDefaultRedirect();
            $this->dispatch('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            SaveConfiguration::run($this->server, $this->proxy_settings);
            $this->server->proxy->redirect_url = $this->redirect_url;
            $this->server->save();
            $this->server->setupDefaultRedirect();
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
