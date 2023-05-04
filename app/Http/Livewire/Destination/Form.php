<?php

namespace App\Http\Livewire\Destination;

use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;

class Form extends Component
{
    public $destination_uuid;
    public $destination;

    protected $rules = [
        'destination.name' => 'required',
        'destination.network' => 'required',
        'destination.server.ip' => 'required',
    ];
    public function mount()
    {
        $standalone = StandaloneDocker::where('uuid', $this->destination_uuid)->first();
        $swarm = SwarmDocker::where('uuid', $this->destination_uuid)->first();
        if (!$standalone && !$swarm) {
            abort(404);
        }
        $this->destination = $standalone ? $standalone->load(['server']) : $swarm->load(['server']);
    }
    public function submit()
    {
        $this->validate();
        $this->destination->save();
    }
    public function delete()
    {
        instantRemoteProcess(['docker network rm -f ' . $this->destination->network], $this->destination->server);
        $this->destination->delete();
        return redirect()->route('dashboard');
    }
}
