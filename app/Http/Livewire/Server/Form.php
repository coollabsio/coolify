<?php

namespace App\Http\Livewire\Server;

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
        'server.name' => 'required|min:6',
        'server.description' => 'nullable',
        'server.ip' => 'required',
        'server.user' => 'required',
        'server.port' => 'required',
        'server.settings.is_cloudflare_tunnel' => 'required',
        'server.settings.is_reachable' => 'required',
        'server.settings.is_part_of_swarm' => 'required',
        'wildcard_domain' => 'nullable|url',
    ];
    protected $validationAttributes = [
        'server.name' => 'Name',
        'server.description' => 'Description',
        'server.ip' => 'IP address/Domain',
        'server.user' => 'User',
        'server.port' => 'Port',
        'server.settings.is_cloudflare_tunnel' => 'Cloudflare Tunnel',
        'server.settings.is_reachable' => 'is reachable',
        'server.settings.is_part_of_swarm' => 'is part of swarm'
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
        refresh_server_connection($this->server->privateKey);
        $this->validateServer();
        $this->server->settings->save();
    }
    public function installDocker($supported_os_type)
    {
        $this->emit('installDocker');
        $this->dockerInstallationStarted = true;
        $activity = InstallDocker::run($this->server, $supported_os_type);
        $this->emit('newMonitorActivity', $activity->id);
    }
    public function checkLocalhostConnection()
    {
        $uptime = $this->server->validateConnection();
        if ($uptime) {
            $this->emit('success', 'Server is reachable.');
            $this->server->settings->is_reachable = true;
            $this->server->settings->is_usable = true;
            $this->server->settings->save();
        } else {
            $this->emit('error', 'Server is not reachable. Please check your connection and configuration.');
            return;
        }
    }
    public function validateServer($install = true)
    {
        try {
            $uptime = $this->server->validateConnection();
            if (!$uptime) {
                $install && $this->emit('error', 'Server is not reachable. Please check your connection and configuration.');
                return;
            }
            $supported_os_type = $this->server->validateOS();
            if (!$supported_os_type) {
                $install && $this->emit('error', 'Server OS is not supported.<br>Please use a supported OS.');
                return;
            }
            $dockerInstalled = $this->server->validateDockerEngine();
            if ($dockerInstalled) {
                $install && $this->emit('success', 'Docker Engine is installed.<br> Checking version.');
            } else {
                $install && $this->installDocker($supported_os_type);
                return;
            }
            $dockerVersion = $this->server->validateDockerEngineVersion();
            if ($dockerVersion) {
                $install && $this->emit('success', 'Docker Engine version is 23+.');
            } else {
                $install && $this->installDocker($supported_os_type);
                return;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->emit('proxyStatusUpdated');
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
            $this->emit('error', 'IP address is already in use by another team.');
            return;
        }
        refresh_server_connection($this->server->privateKey);
        $this->server->settings->wildcard_domain = $this->wildcard_domain;
        $this->server->settings->cleanup_after_percentage = $this->cleanup_after_percentage;
        $this->server->settings->save();
        $this->server->save();
        $this->emit('success', 'Server updated successfully.');
    }
}
