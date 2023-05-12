<?php

namespace App\Http\Livewire\Destination;

use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;

class Form extends Component
{
    public mixed $destination;

    protected $rules = [
        'destination.name' => 'required',
        'destination.network' => 'required',
        'destination.server.ip' => 'required',
    ];
    public function submit()
    {
        $this->validate();
        $this->destination->save();
    }
    public function delete()
    {
        // instantRemoteProcess(['docker network rm -f ' . $this->destination->network], $this->destination->server);
        $this->destination->delete();
        return redirect()->route('dashboard');
    }
}
