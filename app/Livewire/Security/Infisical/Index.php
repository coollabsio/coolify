<?php

namespace App\Livewire\Security\Infisical;

use App\Actions\Infisical\SyncEnvironmentSecrets;
use App\Models\Environment;
use App\Models\InfisicalBinding;
use App\Models\InfisicalConnection;
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
     * "Sync now" is authorized against the binding's `update` ability rather
     * than a bespoke `syncNow` ability: the policies only define viewAny,
     * view, create, update, delete, and authorizing an undefined ability
     * throws instead of denying.
     */
    public function syncNow(string $uuid): void
    {
        $binding = InfisicalBinding::query()
            ->whereHas('connection', fn ($query) => $query->where('team_id', currentTeam()->id))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->authorize('update', $binding);

        try {
            SyncEnvironmentSecrets::run($binding);

            $this->dispatch('success', 'Infisical secrets synced.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to sync secrets.', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.security.infisical.index');
    }
}
