<?php

namespace App\Livewire\Destination;

use App\Jobs\RestartProxyJob;
use App\Models\StandaloneDocker;
use App\Rules\ValidServerIp;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

    public ?string $bindIp = null;

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
            $this->validateBindIp();

            $this->destination->name = $this->name;
            $this->destination->network = $this->network;
            $this->destination->bind_ip = blank($this->bindIp) ? null : $this->bindIp;
            $this->destination->server->ip = $this->serverIp;

            $bindIpChanged = $this->destination->isDirty('bind_ip');
            $this->destination->save();

            if ($bindIpChanged && $this->destination->getMorphClass() === StandaloneDocker::class && ! $this->destination->server->isSwarm()) {
                RestartProxyJob::dispatch($this->destination->server);
            }
        } else {
            $this->name = $this->destination->name;
            $this->network = $this->destination->network;
            $this->bindIp = $this->destination->bind_ip;
            $this->serverIp = $this->destination->server->ip;
        }
    }

    private function validateBindIp(): void
    {
        if (blank($this->bindIp)) {
            return;
        }

        Validator::make(
            ['bindIp' => $this->bindIp],
            ['bindIp' => ['string', new ValidServerIp]],
        )->validate();

        if ($this->bindIp === $this->destination->server->ip) {
            throw ValidationException::withMessages([
                'bindIp' => 'Bind IP must differ from the server IP.',
            ]);
        }

        $duplicate = StandaloneDocker::query()
            ->where('server_id', $this->destination->server_id)
            ->where('bind_ip', $this->bindIp)
            ->where('id', '!=', $this->destination->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'bindIp' => 'Bind IP is already used by another destination on this server.',
            ]);
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
