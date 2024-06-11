<?php

namespace App\Livewire\Destination;

use Livewire\Component;

class Form extends Component
{
    public mixed $destination;

    protected $rules = [
        'destination.name' => 'required',
        'destination.network' => 'required',
        'destination.server.ip' => 'required',
    ];

    protected $validationAttributes = [
        'destination.name' => 'name',
        'destination.network' => 'network',
        'destination.server.ip' => 'IP Address/Domain',
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
                    return $this->dispatch('error', 'You must delete all resources before deleting this destination.');
                }
                instant_remote_process(["docker network disconnect {$this->destination->network} coolify-proxy"], $this->destination->server, throwError: false);
                instant_remote_process(['docker network rm -f '.$this->destination->network], $this->destination->server);
            }
            $this->destination->delete();

            return redirect()->route('dashboard');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
