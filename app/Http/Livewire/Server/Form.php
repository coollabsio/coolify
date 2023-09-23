<?php

namespace App\Http\Livewire\Server;

use App\Actions\Server\InstallDocker;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesRequests;
    public Server $server;
    public $uptime;
    public $dockerVersion;
    public string|null $wildcard_domain = null;
    public int $cleanup_after_percentage;
    public bool $dockerInstallationStarted = false;

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
        'server.name' => 'name',
        'server.description' => 'description',
        'server.ip' => 'ip',
        'server.user' => 'user',
        'server.port' => 'port',
        'server.settings.is_cloudflare_tunnel' => 'Cloudflare Tunnel',
        'server.settings.is_reachable' => 'is reachable',
        'server.settings.is_part_of_swarm' => 'is part of swarm'
    ];

    public function mount()
    {
        $this->wildcard_domain = $this->server->settings->wildcard_domain;
        $this->cleanup_after_percentage = $this->server->settings->cleanup_after_percentage;
    }
    public function instantSave() {
        $this->server->settings->save();
    }
    public function installDocker()
    {
        $this->dockerInstallationStarted = true;
        $activity = resolve(InstallDocker::class)($this->server);
        $this->emit('newMonitorActivity', $activity->id);
    }

    public function validateServer()
    {
        try {
            ['uptime' => $uptime, 'dockerVersion' => $dockerVersion] = validateServer($this->server, true);
            if ($uptime) {
                $this->uptime = $uptime;
                $this->emit('success', 'Server is reachable.');
            } else {
                ray($this->uptime);

                $this->emit('error', 'Server is not reachable.');

                return;
            }
            if ($dockerVersion) {
                $this->dockerVersion = $dockerVersion;
                $this->emit('proxyStatusUpdated');
                $this->emit('success', 'Docker Engine 23+ is installed!');
            } else {
                $this->emit('error', 'No Docker Engine or older than 23 version installed.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this, customErrorMessage: "Server is not reachable: ");
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->server);
            if (!$this->server->isEmpty()) {
                $this->emit('error', 'Server has defined resources. Please delete them first.');
                return;
            }
            $this->server->delete();
            return redirect()->route('server.all');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function submit()
    {
        $this->validate();
        $uniqueIPs = Server::all()->reject(function (Server $server) {
            return $server->id === $this->server->id;
        })->pluck('ip')->toArray();
        if (in_array($this->server->ip, $uniqueIPs)) {
            $this->emit('error', 'IP address is already in use by another team.');
            return;
        }
        $this->server->settings->wildcard_domain = $this->wildcard_domain;
        $this->server->settings->cleanup_after_percentage = $this->cleanup_after_percentage;
        $this->server->settings->save();
        $this->server->save();
        $this->emit('success', 'Server updated successfully.');
    }
}
