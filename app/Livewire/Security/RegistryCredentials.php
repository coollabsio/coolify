<?php

namespace App\Livewire\Security;

use App\Models\RegistryCredential;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class RegistryCredentials extends Component
{
    use AuthorizesRequests;

    /** @var Collection<int, RegistryCredential> */
    public Collection $credentials;

    public function mount(): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->authorize('viewAny', RegistryCredential::class);
        $this->loadCredentials();
    }

    #[On('registryCredentialSaved')]
    public function loadCredentials(): void
    {
        $this->credentials = RegistryCredential::ownedByCurrentTeam()->withCount('workloads')->orderBy('name')->get();
    }

    public function delete(string $uuid): void
    {
        $credential = RegistryCredential::ownedByCurrentTeam()->whereUuid($uuid)->firstOrFail();
        $this->authorize('delete', $credential);
        if ($blocker = $credential->deletionBlocker()) {
            $this->dispatch('error', $blocker);

            return;
        }

        $credential->delete();
        auditLog('ui.registry_credential.deleted', [
            'team_id' => $credential->team_id,
            'registry_credential_uuid' => $credential->uuid,
            'registry' => $credential->registry,
        ]);
        $this->loadCredentials();
        $this->dispatch('success', 'Registry credential deleted.');
    }

    public function render(): View
    {
        return view('livewire.security.registry-credentials');
    }
}
