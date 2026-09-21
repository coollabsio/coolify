<?php

namespace App\Livewire\Security\Infisical;

use App\Jobs\InfisicalAdoptJob;
use App\Models\InfisicalConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', InfisicalConnection::class);
    }

    /**
     * There is at most one connection per team - the table carries a unique
     * index on `team_id` - so the screen shows either the empty state or this
     * single connection, never a list.
     */
    public function getConnectionProperty(): ?InfisicalConnection
    {
        return InfisicalConnection::ownedByCurrentTeam()->first();
    }

    #[On('infisicalConnectionSaved')]
    public function refreshConnection(): void
    {
        // The computed property is resolved fresh on the next render.
    }

    /**
     * Arm the sync and push every existing Coolify variable up, once.
     *
     * The push is queued rather than run inline: it walks every project,
     * environment and resource in the team and makes an API call per folder.
     * Progress is read back off the connection's sync columns, which
     * AdoptTeamSecretsIntoInfisical writes.
     */
    public function enableConnection(): void
    {
        $connection = $this->connection;

        if ($connection === null) {
            return;
        }

        $this->authorize('update', $connection);

        try {
            if ($connection->is_enabled) {
                return;
            }

            $connection->forceFill([
                'is_enabled' => true,
                'last_sync_status' => null,
                'last_sync_error' => null,
            ])->save();

            InfisicalAdoptJob::dispatch($connection);

            $this->dispatch(
                'success',
                'Infisical sync enabled. Pushing this team\'s existing variables up now - this runs in the background.'
            );
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Disarm the sync without touching any variable.
     *
     * Everything already synced stays exactly where it is and becomes editable
     * in Coolify again. Nothing is deleted - the sync is additive-only by
     * design, in both directions.
     */
    public function disableConnection(): void
    {
        $connection = $this->connection;

        if ($connection === null) {
            return;
        }

        $this->authorize('update', $connection);

        try {
            $connection->forceFill(['is_enabled' => false])->save();

            $this->dispatch('success', 'Infisical sync disabled. Coolify variables are editable again.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Delete the connection.
     *
     * Since the lock is armed by an *enabled* connection, deleting it is what
     * disarms the lock: the variables Coolify has already synced stay in place
     * and become editable here again. Nothing removes those rows - that is the
     * spec's additive-only policy, not an oversight.
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
