<?php

namespace App\Livewire\Destination;

use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Auth\Access\AuthorizationException;
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
            $this->authorize('view', $destination);

            $this->destination = $destination;
            $this->syncData();
        } catch (AuthorizationException) {
            abort(403);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->destination->name = $this->name;
            $this->destination->network = $this->network;
            $this->destination->server->ip = $this->serverIp;
            $changedFields = auditChangedFields($this->destination);
            $this->destination->save();
            if ($changedFields !== []) {
                auditLog('ui.destination.updated', $this->auditContext([
                    'changed_fields' => $changedFields,
                ]));
            }
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
                instant_remote_process([dockerNetworkRemoveCommand($this->destination->network)], $this->destination->server);
            }
            $auditContext = $this->auditContext();
            $this->destination->delete();
            auditLog('ui.destination.deleted', $auditContext);

            return redirectRoute($this, 'destination.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function auditContext(array $context = []): array
    {
        return array_merge([
            'team_id' => $this->destination->server?->team_id,
            'destination_uuid' => $this->destination->uuid,
            'destination_name' => $this->destination->name,
            'destination_type' => $this->destination instanceof SwarmDocker ? 'swarm' : 'standalone',
            'server_uuid' => $this->destination->server?->uuid,
        ], $context);
    }

    public function render()
    {
        return view('livewire.destination.show');
    }
}
