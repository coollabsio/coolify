<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckConfiguration;
use App\Actions\Proxy\SaveConfiguration;
use App\Models\Server;
use Livewire\Component;
use Illuminate\Support\Str;

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
        $this->dispatch('proxyStatusUpdated');
    }

    public function submit()
    {
        try {
            SaveConfiguration::run($this->server, $this->proxy_settings);
            $this->server->proxy->redirect_url = $this->redirect_url;
            $this->server->save();

            setup_default_redirect_404(redirect_url: $this->server->proxy->redirect_url, server: $this->server);
            $this->dispatch('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function reset_proxy_configuration()
    {
        try {
            $this->proxy_settings = CheckConfiguration::run($this->server, true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadProxyConfiguration()
    {
        try {
            $this->proxy_settings = CheckConfiguration::run($this->server);
            if (Str::of($this->proxy_settings)->contains('--api.dashboard=true') && Str::of($this->proxy_settings)->contains('--api.insecure=true')) {
                $this->dispatch('traefikDashboardAvailable', true);
            } else {
                $this->dispatch('traefikDashboardAvailable', false);
            }

        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
