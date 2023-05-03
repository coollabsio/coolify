<?php

namespace App\Http\Livewire\Server;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Illuminate\Support\Facades\Validator;
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
    ];
    public function mount()
    {
        $this->server = Server::find($this->server_id);
    }
    public function installDocker()
    {
        $config = base64_encode('{ "live-restore": true }');
        remoteProcess([
            "curl https://releases.rancher.com/install-docker/23.0.sh | sh",
            "echo '{$config}' | base64 -d > /etc/docker/daemon.json",
            "systemctl restart docker"
        ], $this->server, ActivityTypes::INLINE->value);
    }
    public function checkServer()
    {
        try {
            $this->uptime = instantRemoteProcess(['uptime'], $this->server, false);
            if (!$this->uptime) {
                $this->uptime = 'Server not reachable.';
                throw new \Exception('Server not reachable.');
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
        }
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
