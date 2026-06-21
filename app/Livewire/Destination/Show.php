<?php

namespace App\Livewire\Destination;

use App\Models\StandaloneDocker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public $destination;

    #[Validate(['string', 'required'])]
    public string $name;

    #[Validate(['string', 'required', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'])]
    public string $network;

    #[Validate(['string', 'required'])]
    public string $serverIp;

    public function mount(string $destination_uuid)
    {
        try {
            $destination = find_destination_for_current_team($destination_uuid);
            if (! $destination) {
                return redirect()->route('destination.index');
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
            $this->authorize('update', $this->destination);

            $this->syncData(true);
            $this->dispatch('success', 'Destination saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->destination);

            if ($this->destination->getMorphClass() === StandaloneDocker::class) {
                if ($this->destination->attachedTo()) {
                    return $this->dispatch('error', 'You must delete all resources before deleting this destination.');
                }
                $safeNetwork = escapeshellarg($this->destination->network);
                instant_remote_process(["docker network disconnect {$safeNetwork} coolify-proxy"], $this->destination->server, throwError: false);
                instant_remote_process(["docker network rm -f {$safeNetwork}"], $this->destination->server);
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
