<?php

namespace App\Http\Livewire\Server;

use App\Actions\Server\InstallDocker;
use App\Models\Server;
use Livewire\Component;

class Form extends Component
{
    public $server_id;
    public Server $server;
    public $uptime;
    public $dockerVersion;
    public $dockerComposeVersion;

    protected $rules = [
        'server.name' => 'required|min:6',
        'server.description' => 'nullable',
        'server.ip' => 'required',
        'server.user' => 'required',
        'server.port' => 'required',
        'server.settings.is_validated' => 'required'
    ];
    public function mount()
    {
        $this->server = Server::find($this->server_id)->load(['settings']);
    }
    public function installDocker()
    {
        $activity = resolve(InstallDocker::class)($this->server);

        $this->emit('newMonitorActivity', $activity->id);
    }
    public function checkServer()
    {
        try {
            $this->uptime = instantRemoteProcess(['uptime'], $this->server, false);
            if (!$this->uptime) {
                $this->uptime = 'Server not reachable.';
                throw new \Exception('Server not reachable.');
            } else {
                if (!$this->server->settings->is_validated) {
                    $this->server->settings->is_validated = true;
                    $this->server->settings->save();
                }
            }
            $this->dockerVersion = instantRemoteProcess(['docker version|head -2|grep -i version'], $this->server, false);
            if (!$this->dockerVersion) {
                $this->dockerVersion = 'Not installed.';
            }
            $this->dockerComposeVersion = instantRemoteProcess(['docker compose version|head -2|grep -i version'], $this->server, false);
            if (!$this->dockerComposeVersion) {
                $this->dockerComposeVersion = 'Not installed.';
            }
        } catch (\Exception $e) {
            return generalErrorHandlerLivewire($e, $this);
        }
    }
    public function delete()
    {
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
