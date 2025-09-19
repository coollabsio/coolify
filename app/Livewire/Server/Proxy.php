<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Proxy extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public ?string $selectedProxy = null;

    public $proxySettings = null;

    public bool $redirectEnabled = true;

    public ?string $redirectUrl = null;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            'saveConfiguration' => 'submit',
            "echo-private:team.{$teamId},ProxyStatusChangedUI" => '$refresh',
        ];
    }

    protected $rules = [
        'server.settings.generate_exact_labels' => 'required|boolean',
    ];

    public function mount()
    {
        $this->selectedProxy = $this->server->proxyType();
        $this->redirectEnabled = data_get($this->server, 'proxy.redirect_enabled', true);
        $this->redirectUrl = data_get($this->server, 'proxy.redirect_url');
    }

    public function getConfigurationFilePathProperty()
    {
        return $this->server->proxyPath().'/docker-compose.yml';
    }

    public function changeProxy()
    {
        $this->authorize('update', $this->server);
        $this->server->proxy = null;
        $this->server->save();

        $this->dispatch('reloadWindow');
    }

    public function selectProxy($proxy_type)
    {
        try {
            $this->authorize('update', $this->server);
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
            $this->authorize('update', $this->server);
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
            $this->authorize('update', $this->server);
            $this->server->proxy->redirect_enabled = $this->redirectEnabled;
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
            $this->authorize('update', $this->server);
            SaveProxyConfiguration::run($this->server, $this->proxySettings);
            $this->server->proxy->redirect_url = $this->redirectUrl;
            $this->server->save();
            $this->server->setupDefaultRedirect();
            $this->dispatch('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resetProxyConfiguration()
    {
        try {
            $this->authorize('update', $this->server);
            // Explicitly regenerate default configuration
            $this->proxySettings = GetProxyConfiguration::run($this->server, forceRegenerate: true);
            SaveProxyConfiguration::run($this->server, $this->proxySettings);
            $this->server->save();
            $this->dispatch('success', 'Proxy configuration reset to default.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadProxyConfiguration()
    {
        try {
            $this->proxySettings = GetProxyConfiguration::run($this->server);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
