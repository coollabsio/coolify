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

    protected $rules = [
        'server.name' => 'required|min:6',
        'server.description' => 'nullable',
        'server.ip' => 'required',
        'server.user' => 'required',
        'server.port' => 'required',
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
        'server.settings.is_reachable' => 'is reachable',
        'server.settings.is_part_of_swarm' => 'is part of swarm'
    ];

    public function mount()
    {
        $this->wildcard_domain = $this->server->settings->wildcard_domain;
        $this->cleanup_after_percentage = $this->server->settings->cleanup_after_percentage;
    }

    public function installDocker()
    {
        $activity = resolve(InstallDocker::class)($this->server, currentTeam());
        $this->emit('newMonitorActivity', $activity->id);
    }

    public function validateServer()
    {
        try {
            ['uptime' => $uptime, 'dockerVersion' => $dockerVersion] = validateServer($this->server);
            if ($uptime) {
                $this->uptime = $uptime;
                $this->emit('success', 'Server is reachable!');
            } else {
                $this->emit('error', 'Server is not reachable');
                return;
            }
            if ($dockerVersion) {
                $this->dockerVersion = $dockerVersion;
                $this->emit('proxyStatusUpdated');
                $this->emit('success', 'Docker Engine 23+ is installed!');
            } else {
                $this->emit('error', 'Old (lower than 23) or no Docker version detected. Install Docker Engine on the General tab.');
            }
        } catch (\Throwable $e) {
            return general_error_handler($e, that: $this);
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
            return general_error_handler(err: $e, that: $this);
        }
    }
    public function submit()
    {
        $this->validate();

        $this->server->settings->wildcard_domain = $this->wildcard_domain;
        $this->server->settings->cleanup_after_percentage = $this->cleanup_after_percentage;
        $this->server->settings->save();
        $this->server->save();
        $this->emit('success', 'Server updated successfully.');
    }
}
