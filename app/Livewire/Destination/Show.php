<?php

namespace App\Livewire\Destination;

use App\Models\Server;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Rule;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public $destination;

    #[Rule(['string', 'required'])]
    public string $name;

    #[Rule(['string', 'required'])]
    public string $network;

    #[Rule(['string', 'required'])]
    public string $serverIp;

    public function mount(string $destination_uuid)
    {
        try {
            $destination = Server::isUsable()->whereHas('standaloneDockers', function ($query) use ($destination_uuid) {
                $query->where('uuid', $destination_uuid);
            })->first()->standaloneDockers()->where('uuid', $destination_uuid)->first();

            if (! $destination) {
                $destination = Server::isUsable()->whereHas('swarmDockers', function ($query) use ($destination_uuid) {
                    $query->where('uuid', $destination_uuid);
                })->first()->swarmDockers()->where('uuid', $destination_uuid)->first();
            }
            if (! $destination) {
                throw new \Exception('Destination not found');
            }
            $this->destination = $destination;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->destination->name = $this->name;
            $this->destination->network = $this->network;
            $this->destination->server->ip = $this->serverIp;
            $this->destination->save();
        } else {
            $this->name = $this->destination->name;
            $this->network = $this->destination->network;
            $this->serverIp = $this->destination->server->ip;
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Destination saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            if ($this->destination->getMorphClass() === \App\Models\StandaloneDocker::class) {
                if ($this->destination->attachedTo()) {
                    return $this->dispatch('error', 'You must delete all resources before deleting this destination.');
                }
                instant_remote_process(["docker network disconnect {$this->destination->network} coolify-proxy"], $this->destination->server, throwError: false);
                instant_remote_process(['docker network rm -f '.$this->destination->network], $this->destination->server);
            }
            $this->destination->delete();

            return redirect()->route('destination.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.destination.show');
    }
}
