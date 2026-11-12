<?php

namespace App\Livewire\Security;

use App\Models\RegistryCredential;
use App\Services\DockerRegistryLogins;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Creates a registry credential, or edits one when it gets its UUID. */
class RegistryCredentialForm extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?string $registry_credential_uuid = null;

    public string $name = '';

    public string $registry = DockerRegistryLogins::DOCKER_HUB;

    public string $username = '';

    public string $password = '';

    public function mount(?string $registry_credential_uuid = null): void
    {
        if ($registry_credential_uuid === null) {
            $this->authorize('create', RegistryCredential::class);

            return;
        }
        $credential = $this->credential($registry_credential_uuid);
        $this->authorize('update', $credential);
        $this->registry_credential_uuid = $credential->uuid;
        $this->name = $credential->name;
        $this->registry = $credential->registry;
        $this->username = $credential->username;
    }

    public function save(): void
    {
        $credential = $this->registry_credential_uuid === null ? null : $this->credential($this->registry_credential_uuid);
        $credential === null ? $this->authorize('create', RegistryCredential::class) : $this->authorize('update', $credential);

        $this->registry = DockerRegistryLogins::normalizeRegistry($this->registry);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'registry' => ['required', 'string', 'max:255', 'regex:'.DockerRegistryLogins::REGISTRY_PATTERN],
            'username' => ['required', 'string', 'max:255', 'not_regex:/[:\x00-\x1F\x7F]/'],
            'password' => [$credential === null ? 'required' : 'nullable', 'string', 'max:8192', 'not_regex:/[\x00-\x1F\x7F]/'],
        ], [
            'registry.regex' => 'Enter a registry host, for example ghcr.io or registry.example.com:5000.',
            'username.not_regex' => 'The username cannot contain a colon or control characters.',
            'password.not_regex' => 'The password cannot contain control characters.',
        ]);
        $attributes = [
            'name' => trim($validated['name']),
            'registry' => $validated['registry'],
            'username' => $validated['username'],
            ...(filled($validated['password']) ? ['password' => $validated['password']] : []),
        ];

        if ($credential === null) {
            $credential = RegistryCredential::query()->create(['team_id' => currentTeam()->id, ...$attributes]);
            $this->reset(['name', 'registry', 'username']);
        } else {
            $credential->update($attributes);
        }
        $this->password = '';
        auditLog($credential->wasRecentlyCreated ? 'ui.registry_credential.created' : 'ui.registry_credential.updated', [
            'team_id' => $credential->team_id,
            'registry_credential_uuid' => $credential->uuid,
            'registry' => $credential->registry,
        ]);
        $this->dispatch('registryCredentialSaved');
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Registry credential saved.');
    }

    private function credential(string $uuid): RegistryCredential
    {
        return RegistryCredential::ownedByCurrentTeam()->whereUuid($uuid)->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.security.registry-credential-form');
    }
}
