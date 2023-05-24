<?php

namespace App\Http\Livewire\Destination;

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
        try {
            if ($this->destination->getMorphClass() === 'App\Models\StandaloneDocker') {
                if ($this->destination->attachedTo()) {
                    return $this->emit('error', 'You must delete all resources before deleting this destination.');
                }
                instantRemoteProcess(["docker network disconnect {$this->destination->network} coolify-proxy"], $this->destination->server, throwError: false);
                instantRemoteProcess(['docker network rm -f ' . $this->destination->network], $this->destination->server);
            }
            $this->destination->delete();
            return redirect()->route('dashboard');
        } catch (\Exception $e) {
            return general_error_handler($e);
        }
    }
}
