<?php

namespace App\Http\Livewire\Destination\New;

use App\Models\Server;
use App\Models\StandaloneDocker as ModelsStandaloneDocker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class StandaloneDocker extends Component
{
    public string $name;
    public string $network;

    public Collection $servers;
    public Server $server;
    public int|null $server_id = null;

    protected $rules = [
        'name' => 'required|string',
        'network' => 'required|string',
        'server_id' => 'required|integer'
    ];
    protected $validationAttributes = [
        'name' => 'name',
        'network' => 'network',
        'server_id' => 'server'
    ];

    public function mount()
    {
        if (request()->query('server_id')) {
            $this->server_id = request()->query('server_id');
        } else {
            if ($this->servers->count() > 0) {
                $this->server_id = $this->servers->first()->id;
            }
        }
        if (request()->query('network_name')) {
            $this->network = request()->query('network_name');
        } else {
            $this->network = new Cuid2(7);
        }
        $this->name = Str::kebab("{$this->servers->first()->name}-{$this->network}");
    }

    public function generate_name()
    {
        $this->server = Server::find($this->server_id);
        $this->name = Str::kebab("{$this->server->name}-{$this->network}");
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->server = Server::find($this->server_id);
            $found = $this->server->standaloneDockers()->where('network', $this->network)->first();
            if ($found) {
                $this->createNetworkAndAttachToProxy();
                $this->addError('network', 'Network already added to this server.');
                return;
            } else {
                $docker = ModelsStandaloneDocker::create([
                    'name' => $this->name,
                    'network' => $this->network,
                    'server_id' => $this->server_id,
                    'team_id' => session('currentTeam')->id
                ]);
            }
            $this->createNetworkAndAttachToProxy();
            return redirect()->route('destination.show', $docker->uuid);
        } catch (\Exception $e) {
            return general_error_handler(err: $e);
        }
    }

    private function createNetworkAndAttachToProxy()
    {
        instant_remote_process(['docker network create --attachable ' . $this->network], $this->server, throwError: false);
        instant_remote_process(["docker network connect $this->network coolify-proxy"], $this->server, throwError: false);
    }
}
