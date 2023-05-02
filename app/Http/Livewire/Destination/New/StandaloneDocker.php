<?php

namespace App\Http\Livewire\Destination\New;

use App\Models\Server;
use App\Models\StandaloneDocker as ModelsStandaloneDocker;
use Livewire\Component;

class StandaloneDocker extends Component
{
    public string $name;
    public string $network;

    public $servers;
    public int|null $server_id = null;

    protected $rules = [
        'name' => 'required|string',
        'network' => 'required|string',
        'server_id' => 'required|integer'
    ];
    public function mount()
    {
        $this->name = generateRandomName();
        $this->servers = Server::where('team_id', session('currentTeam')->id)->get();
    }
    public function setServerId($server_id)
    {
        $this->server_id = $server_id;
    }
    public function submit()
    {
        $this->validate();
        $found = ModelsStandaloneDocker::where('server_id', $this->server_id)->where('network', $this->network)->first();
        if ($found) {
            $this->addError('network', 'Network already added to this server.');
            return;
        }
        $docker = ModelsStandaloneDocker::create([
            'name' => $this->name,
            'network' => $this->network,
            'server_id' => $this->server_id,
            'team_id' => session('currentTeam')->id
        ]);

        $server = Server::find($this->server_id);

        runRemoteCommandSync($server, ['docker network create --attachable ' . $this->network], throwError: false);
        return redirect()->route('destination.show', $docker->uuid);
    }
}
