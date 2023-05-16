<?php

namespace App\Http\Livewire\Destination\New;

use App\Models\Server;
use App\Models\StandaloneDocker as ModelsStandaloneDocker;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class StandaloneDocker extends Component
{
    public string $name;
    public string $network;

    public Collection $servers;
    public int|null $server_id = null;

    protected $rules = [
        'name' => 'required|string',
        'network' => 'required|string',
        'server_id' => 'required|integer'
    ];
    public function mount()
    {
        if (!$this->server_id) {
            if (request()->query('server_id')) {
                $this->server_id = request()->query('server_id');
            } else {
                if ($this->servers->count() > 0) {
                    $this->server_id = $this->servers->first()->id;
                }
            }
        }
        $this->network = new Cuid2(7);
        $this->name = generateRandomName();
    }

    public function submit()
    {
        $this->validate();
        $found = ModelsStandaloneDocker::where('server_id', $this->server_id)->where('network', $this->network)->first();
        if ($found) {
            $this->addError('network', 'Network already added to this server.');
            return;
        }
        $server = Server::find($this->server_id);
        instantRemoteProcess(['docker network create --attachable ' . $this->network], $server, throwError: false);
        instantRemoteProcess(["docker network connect $this->network coolify-proxy"], $server, throwError: false);

        $docker = ModelsStandaloneDocker::create([
            'name' => $this->name,
            'network' => $this->network,
            'server_id' => $this->server_id,
            'team_id' => session('currentTeam')->id
        ]);


        return redirect()->route('destination.show', $docker->uuid);
    }
}
