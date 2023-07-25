<?php

namespace App\Http\Livewire\Server;

use App\Actions\Server\InstallDocker;
use App\Models\Server;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Form extends Component
{
    public Server $server;
    public $uptime;
    public $dockerVersion;
    public string|null $wildcard_domain = null;
    public int $cleanup_after_percentage;
    public string|null $modalId = null;

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
        $this->modalId = new Cuid2(7);
        $this->wildcard_domain = $this->server->settings->wildcard_domain;
        $this->cleanup_after_percentage = $this->server->settings->cleanup_after_percentage;
    }
    public function installDocker()
    {
        $activity = resolve(InstallDocker::class)($this->server, session('currentTeam'));
        $this->emit('newMonitorActivity', $activity->id);
    }
    public function validateServer()
    {
        try {
            ['uptime' => $uptime, 'dockerVersion' => $dockerVersion] = validateServer($this->server);
            if ($uptime) {
                $this->uptime = $uptime;
            }
            if ($dockerVersion) {
                $this->dockerVersion = $dockerVersion;
                $this->emit('proxyStatusUpdated');
            }
        } catch (\Exception $e) {
            return general_error_handler(customErrorMessage: "Server is not reachable. Reason: {$e->getMessage()}", that: $this);
        }
    }
    public function delete()
    {
        if (!$this->server->isEmpty()) {
            $this->emit('error', 'Server has defined resources. Please delete them first.');
            return;
        }
        $this->server->delete();
        redirect()->route('server.all');
    }
    public function submit()
    {
        $this->validate();
        // $validation = Validator::make($this->server->toArray(), [
        //     'ip' => [
        //         'ip'
        //     ],
        // ]);
        // if ($validation->fails()) {
        //     foreach ($validation->errors()->getMessages() as $key => $value) {
        //         $this->addError("server.{$key}", $value[0]);
        //     }
        //     return;
        // }
        $this->server->settings->wildcard_domain = $this->wildcard_domain;
        $this->server->settings->cleanup_after_percentage = $this->cleanup_after_percentage;
        $this->server->settings->save();
        $this->server->save();
        $this->emit('success', 'Server updated successfully.');
    }
}
