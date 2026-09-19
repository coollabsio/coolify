<?php

namespace App\Livewire\Security\Infisical;

use App\Actions\Infisical\SyncEnvironmentSecrets;
use App\Models\Environment;
use App\Models\InfisicalBinding;
use App\Models\InfisicalConnection;
use App\Services\Infisical\InfisicalApiException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public int $bindingConnectionId = 0;

    public int $bindingEnvironmentId = 0;

    public string $bindingProjectId = '';

    public string $bindingEnvironmentSlug = '';

    public string $bindingSecretPath = '/';

    public function mount(): void
    {
        $this->authorize('viewAny', InfisicalConnection::class);
    }

    public function getConnectionsProperty(): Collection
    {
        return InfisicalConnection::ownedByCurrentTeam()->get();
    }

    /**
     * Bindings owned by the current team, resolved through their connection.
     * A binding has no team_id column of its own; the join guarantees the
     * listing never leaks rows whose connection belongs to another team.
     */
    public function getBindingsProperty(): Collection
    {
        return InfisicalBinding::query()
            ->whereHas('connection', fn ($query) => $query->where('team_id', currentTeam()->id))
            ->with(['connection', 'environment.project'])
            ->get();
    }

    public function getAvailableEnvironmentsProperty(): Collection
    {
        $boundEnvironmentIds = InfisicalBinding::query()->pluck('environment_id');

        return Environment::query()
            ->whereHas('project', fn ($query) => $query->where('team_id', currentTeam()->id))
            ->whereNotIn('id', $boundEnvironmentIds)
            ->with('project')
            ->get();
    }

    protected function bindingRules(): array
    {
        return [
            'bindingConnectionId' => ['required', 'integer', 'min:1'],
            'bindingEnvironmentId' => ['required', 'integer', 'min:1'],
            'bindingProjectId' => ['required', 'string', 'max:255'],
            'bindingEnvironmentSlug' => ['required', 'string', 'max:255'],
            'bindingSecretPath' => ['required', 'string', 'max:255'],
        ];
    }

    protected function bindingMessages(): array
    {
        return [
            'bindingConnectionId.required' => 'Select a connection.',
            'bindingEnvironmentId.required' => 'Select an environment.',
            'bindingProjectId.required' => 'The Infisical project ID field is required.',
            'bindingEnvironmentSlug.required' => 'The Infisical environment slug field is required.',
        ];
    }

    public function createBinding(): void
    {
        try {
            $this->authorize('create', InfisicalBinding::class);

            $this->validate($this->bindingRules(), $this->bindingMessages());

            // Never trust the browser-supplied ids: re-resolve both rows scoped
            // to the current team before using them.
            $connection = InfisicalConnection::ownedByCurrentTeam()
                ->whereKey($this->bindingConnectionId)
                ->firstOrFail();

            $environment = Environment::query()
                ->whereKey($this->bindingEnvironmentId)
                ->whereHas('project', fn ($query) => $query->where('team_id', currentTeam()->id))
                ->firstOrFail();

            InfisicalBinding::create([
                'infisical_connection_id' => $connection->id,
                'environment_id' => $environment->id,
                'infisical_project_id' => $this->bindingProjectId,
                'infisical_environment_slug' => $this->bindingEnvironmentSlug,
                'secret_path' => $this->bindingSecretPath !== '' ? $this->bindingSecretPath : '/',
                'is_enabled' => true,
            ]);

            $this->reset(['bindingConnectionId', 'bindingEnvironmentId', 'bindingProjectId', 'bindingEnvironmentSlug', 'bindingSecretPath']);
            $this->bindingSecretPath = '/';

            $this->dispatch('success', 'Environment bound to Infisical.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Resolve a binding by uuid, scoped to the current team through its
     * connection. Never trust the browser-supplied identifier.
     */
    private function findBinding(string $uuid): InfisicalBinding
    {
        return InfisicalBinding::query()
            ->whereHas('connection', fn ($query) => $query->where('team_id', currentTeam()->id))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * "Sync now" is authorized against the binding's `update` ability rather
     * than a bespoke `syncNow` ability: the policies only define viewAny,
     * view, create, update, delete, and authorizing an undefined ability
     * throws instead of denying.
     */
    public function syncNow(string $uuid): void
    {
        $binding = $this->findBinding($uuid);

        $this->authorize('update', $binding);

        try {
            SyncEnvironmentSecrets::run($binding);

            $this->dispatch('success', 'Infisical secrets synced.');
        } catch (InfisicalApiException $e) {
            // InfisicalApiException messages are built by us and safe to show.
            $this->dispatch('error', 'Failed to sync secrets.', $e->getMessage());
        } catch (\Throwable $e) {
            // Anything else (QueryException, TypeError, ...) can carry SQL or
            // internal detail: log it and show a bounded message instead.
            report($e);

            $this->dispatch('error', 'Failed to sync secrets.', 'An unexpected error occurred. Check the instance logs for details.');
        }
    }

    /**
     * Enable or disable a binding. A disabled binding is skipped by both the
     * scheduled sync and the resolver, so its already-synced rows stop being
     * injected without being deleted.
     */
    public function toggleBinding(string $uuid): void
    {
        $binding = $this->findBinding($uuid);

        $this->authorize('update', $binding);

        try {
            $binding->is_enabled = ! $binding->is_enabled;
            $binding->save();

            $this->dispatch('success', $binding->is_enabled ? 'Binding enabled.' : 'Binding disabled.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Delete a binding. The shared_environment_variables FK is
     * `cascadeOnDelete`, so every row this binding synced goes with it --
     * that is intentional: the rows exist only as a projection of the binding
     * and nothing else can keep them current. Operators who want to keep the
     * values should disable the binding instead.
     */
    public function deleteBinding(string $uuid): void
    {
        $binding = $this->findBinding($uuid);

        $this->authorize('delete', $binding);

        try {
            $binding->delete();

            $this->dispatch('success', 'Binding deleted. Its synced secrets were removed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Delete a connection. Bindings cascade from the connection, and synced
     * shared variables cascade from those bindings.
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
