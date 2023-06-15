<?php

namespace App\Http\Livewire\Server;

use App\Actions\Server\InstallDocker;
use App\Models\Server;
use Livewire\Component;

class Form extends Component
{
    public Server $server;
    public $uptime;
    public $dockerVersion;

    protected $rules = [
        'server.name' => 'required|min:6',
        'server.description' => 'nullable',
        'server.ip' => 'required',
        'server.user' => 'required',
        'server.port' => 'required',
        'server.settings.is_reachable' => 'required',
        'server.settings.is_part_of_swarm' => 'required'
    ];
    public function installDocker()
    {
        $activity = resolve(InstallDocker::class)($this->server);
        $this->emit('newMonitorActivity', $activity->id);
    }
    public function validateServer()
    {
        try {
            $this->uptime = instant_remote_process(['uptime'], $this->server, false);
            if ($this->uptime) {
                $this->server->settings->is_reachable = true;
                $this->server->settings->save();
            } else {
                $this->uptime = 'Server not reachable.';
                throw new \Exception('Server not reachable.');
            }
            $this->dockerVersion = instant_remote_process(['docker version|head -2|grep -i version'], $this->server, false);
            if (!$this->dockerVersion) {
                $this->dockerVersion = 'Not installed.';
            } else {
                $this->server->settings->is_usable = true;
                $this->server->settings->save();
                $this->emit('serverValidated');
            }
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
    public function delete()
    {
        if (!$this->server->isEmpty()) {
            $this->emit('error', 'Server has defined resources. Please delete them first.');
            return;
        }
        $this->server->delete();
        redirect()->route('dashboard');
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
        $this->server->save();
    }
}
