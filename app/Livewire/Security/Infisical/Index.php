<?php

namespace App\Livewire\Security\Infisical;

use App\Models\InfisicalConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', InfisicalConnection::class);
    }

    public function getConnectionsProperty(): Collection
    {
        return InfisicalConnection::ownedByCurrentTeam()->get();
    }

    /**
     * Delete a connection. Coolify keeps the variables it has already synced;
     * they simply stop being refreshed.
     */
    public function deleteConnection(string $uuid): void
    {
        $connection = InfisicalConnection::ownedByCurrentTeam()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->authorize('delete', $connection);

        try {
            $connection->delete();

            $this->dispatch('success', 'Connection deleted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.infisical.index');
    }
}
