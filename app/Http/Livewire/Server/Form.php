<?php

namespace App\Http\Livewire\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class Form extends Component
{
    public $server_id;
    public Server $server;
    public $uptime;

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
        runRemoteCommandSync($this->server, [
            "curl https://releases.rancher.com/install-docker/23.0.sh | sh"
        ]);
    }
    public function checkConnection()
    {
        $this->uptime = runRemoteCommandSync($this->server, ['uptime']);
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
