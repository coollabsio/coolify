<?php

namespace App\Livewire\Server;

use App\Actions\Server\InstallDocker;
use App\Models\Server;
use Livewire\Component;

class Form extends Component
{
    public Server $server;
    public bool $isValidConnection = false;
    public bool $isValidDocker = false;
    public ?string $wildcard_domain = null;
    public int $cleanup_after_percentage;
    public bool $dockerInstallationStarted = false;
    protected $listeners = ['serverRefresh'];

    protected $rules = [
        'server.name' => 'required',
        'server.description' => 'nullable',
        'server.ip' => 'required',
        'server.user' => 'required',
        'server.port' => 'required',
        'server.settings.is_cloudflare_tunnel' => 'required|boolean',
        'server.settings.is_reachable' => 'required',
        'server.settings.is_swarm_manager' => 'required|boolean',
        'server.settings.is_swarm_worker' => 'required|boolean',
        'wildcard_domain' => 'nullable|url',
    ];
    protected $validationAttributes = [
        'server.name' => 'Name',
        'server.description' => 'Description',
        'server.ip' => 'IP address/Domain',
        'server.user' => 'User',
        'server.port' => 'Port',
        'server.settings.is_cloudflare_tunnel' => 'Cloudflare Tunnel',
        'server.settings.is_reachable' => 'Is reachable',
        'server.settings.is_swarm_manager' => 'Swarm Manager',
        'server.settings.is_swarm_worker' => 'Swarm Worker',
    ];

    public function mount()
    {
        $this->wildcard_domain = $this->server->settings->wildcard_domain;
        $this->cleanup_after_percentage = $this->server->settings->cleanup_after_percentage;
    }
    public function serverRefresh($install = true)
    {
        $this->validateServer($install);
    }
    public function instantSave()
    {
        try {
            refresh_server_connection($this->server->privateKey);
            $this->validateServer(false);
            $this->server->settings->save();
            $this->dispatch('success', 'Server updated successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function installDocker()
    {
        $this->dispatch('installDocker');
        $this->dockerInstallationStarted = true;
        $activity = InstallDocker::run($this->server);
        $this->dispatch('newMonitorActivity', $activity->id);
    }
    public function checkLocalhostConnection()
    {
        $uptime = $this->server->validateConnection();
        if ($uptime) {
            $this->dispatch('success', 'Server is reachable.');
            $this->server->settings->is_reachable = true;
            $this->server->settings->is_usable = true;
            $this->server->settings->save();
        } else {
            $this->dispatch('error', 'Server is not reachable.<br>Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/configuration#openssh-server">documentation</a> for further help.');
            return;
        }
    }
    public function validateServer($install = true)
    {
        try {
            $uptime = $this->server->validateConnection();
            if (!$uptime) {
                $install &&  $this->dispatch('error', 'Server is not reachable.<br>Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/configuration#openssh-server">documentation</a> for further help.');
                return;
            }
            $supported_os_type = $this->server->validateOS();
            if (!$supported_os_type) {
                $install && $this->dispatch('error', 'Server OS type is not supported for automated installation. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://coolify.io/docs/servers#install-docker-engine-manually">documentation</a>.');
                return;
            }
            $dockerInstalled = $this->server->validateDockerEngine();
            if ($dockerInstalled) {
                $install && $this->dispatch('success', 'Docker Engine is installed.<br> Checking version.');
            } else {
                $install && $this->installDocker();
                return;
            }
            $dockerVersion = $this->server->validateDockerEngineVersion();
            if ($dockerVersion) {
                $install && $this->dispatch('success', 'Docker Engine version is 22+.');
            } else {
                $install && $this->installDocker();
                return;
            }
            if ($this->server->isSwarm()) {
                $swarmInstalled = $this->server->validateDockerSwarm();
                if ($swarmInstalled) {
                    $install && $this->dispatch('success', 'Docker Swarm is initiated.');
                }
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('proxyStatusUpdated');
        }
    }

    public function submit()
    {
        if (isCloud() && !isDev()) {
            $this->validate();
            $this->validate([
                'server.ip' => 'required',
            ]);
        } else {
            $this->validate();
        }
        $uniqueIPs = Server::all()->reject(function (Server $server) {
            return $server->id === $this->server->id;
        })->pluck('ip')->toArray();
        if (in_array($this->server->ip, $uniqueIPs)) {
            $this->dispatch('error', 'IP address is already in use by another team.');
            return;
        }
        refresh_server_connection($this->server->privateKey);
        $this->server->settings->wildcard_domain = $this->wildcard_domain;
        $this->server->settings->cleanup_after_percentage = $this->cleanup_after_percentage;
        $this->server->settings->save();
        $this->server->save();
        $this->dispatch('success', 'Server updated successfully.');
    }
}
