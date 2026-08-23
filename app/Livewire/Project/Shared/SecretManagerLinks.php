<?php

namespace App\Livewire\Project\Shared;

use App\Models\IntegrationToken;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Manages a resource's single secret manager source and lets the user
 * browse remote key names, add {{vault.KEY}} reference variables, and import
 * all missing keys. Secret values never enter the component state or the DB.
 */
class SecretManagerLinks extends Component
{
    use AuthorizesRequests;

    public $resource;

    public $link;

    public $availableTokens;

    public string $integration_token_uuid = '';

    public array $settings = [];

    /** @var list<string> Remote key names only — values are never stored. */
    public array $keys = [];

    public bool $keysLoaded = false;

    public string $search = '';

    public function mount(): void
    {
        $this->loadData();
    }

    private function loadData(): void
    {
        $this->link = $this->resource->secretManagerLink()->with('integrationToken')->first();
        $this->availableTokens = IntegrationToken::ownedByCurrentTeam()
            ->whereIn('provider', IntegrationToken::SECRET_MANAGER_PROVIDERS)
            ->get()
            ->filter(fn (IntegrationToken $token) => in_array('secrets', $token->capabilities ?? [], true))
            ->values();

        if ($this->link) {
            $this->integration_token_uuid = $this->link->integrationToken->uuid;
            $this->settings = $this->link->settings ?? [];
        }
    }

    public function getSelectedTokenProperty(): ?IntegrationToken
    {
        if (blank($this->integration_token_uuid)) {
            return null;
        }

        return $this->availableTokens->firstWhere('uuid', $this->integration_token_uuid);
    }

    protected function rules(): array
    {
        $rules = [
            'integration_token_uuid' => ['required', 'string'],
        ];

        $rules += match ($this->selectedToken?->provider) {
            'doppler' => $this->selectedToken->dopplerTokenType() === 'service_account'
                ? [
                    'settings.project' => ['required', 'string'],
                    'settings.config' => ['required', 'string'],
                ]
                : [],
            'infisical' => [
                'settings.project_id' => ['required', 'string'],
                'settings.environment' => ['required', 'string'],
                'settings.secret_path' => ['nullable', 'string'],
            ],
            'vault' => [
                'settings.mount' => ['required', 'string'],
                'settings.path' => ['required', 'string'],
            ],
            default => [],
        };

        return $rules;
    }

    /**
     * Auto-save when a token is selected in the dropdown. Existing {{vault.*}}
     * references are intentionally NOT re-checked — missing keys surface at
     * the next deployment.
     */
    public function updatedIntegrationTokenUuid(): void
    {
        try {
            $this->authorize('update', $this->resource);
            $token = $this->selectedToken;

            if (! $token) {
                return;
            }

            if ($this->link?->integrationToken?->provider !== $token->provider
                || $this->link?->integrationToken?->dopplerTokenType() !== $token->dopplerTokenType()) {
                $this->settings = [];
            }

            $settings = array_filter($this->settings, fn ($value) => filled($value));

            $this->resource->secretManagerLink()->updateOrCreate([], [
                'integration_token_id' => $token->id,
                'settings' => $settings ?: null,
            ]);

            $this->resetKeys();
            $this->loadData();
            $this->dispatch('success', 'Secret manager source saved. References resolve at the next deployment.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Auto-save of the provider-specific settings fields (called on blur).
     */
    public function saveSettings(): void
    {
        $this->authorize('update', $this->resource);

        if (! $this->link) {
            return;
        }

        $validated = $this->validate();

        try {

            $settings = array_filter(data_get($validated, 'settings', []), fn ($value) => filled($value));

            $this->link->update(['settings' => $settings ?: null]);
            $this->resetKeys();
            $this->loadData();
            $this->dispatch('success', 'Secret manager settings saved.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeSource(): void
    {
        try {
            $this->authorize('update', $this->resource);
            $this->resource->secretManagerLink()->delete();
            $this->link = null;
            $this->integration_token_uuid = '';
            $this->settings = [];
            $this->resetKeys();
            $this->loadData();
            $this->dispatch('success', 'Secret manager source removed. Existing {{vault.*}} references will fail the next deployment until they are removed too.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function loadKeys(): void
    {
        try {
            $this->authorize('update', $this->resource);

            if (! $this->link) {
                return;
            }

            // Values are fetched into memory, reduced to key names, and discarded.
            $keys = array_keys($this->link->fetchSecrets());
            sort($keys);
            $this->keys = $keys;
            $this->keysLoaded = true;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Could not fetch keys: '.$e->getMessage());
        }
    }

    public function addReference(string $key): void
    {
        try {
            $this->authorize('update', $this->resource);

            if (! in_array($key, $this->keys, true)) {
                return;
            }

            if ($this->resource->environment_variables()->where('key', $key)->exists()) {
                $this->dispatch('error', "A variable with the key {$key} already exists.");

                return;
            }

            $this->resource->environment_variables()->create([
                'key' => $key,
                'value' => '{{vault.'.$key.'}}',
            ]);

            $this->dispatch('refreshEnvs');
            $this->dispatch('success', "Added {$key} as {{vault.{$key}}}.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function importAll(): void
    {
        try {
            $this->authorize('update', $this->resource);

            if (! $this->link) {
                return;
            }

            $imported = $this->link->importMissingReferences();

            $this->dispatch('refreshEnvs');
            $this->dispatch('success', $imported === []
                ? 'All remote keys already exist as variables.'
                : 'Imported '.count($imported).' keys as {{vault.KEY}} references.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function resetKeys(): void
    {
        $this->keys = [];
        $this->keysLoaded = false;
        $this->search = '';
    }

    public function getFilteredKeysProperty(): array
    {
        if (blank($this->search)) {
            return $this->keys;
        }

        return array_values(array_filter(
            $this->keys,
            fn (string $key) => stripos($key, $this->search) !== false,
        ));
    }

    public function render(): View
    {
        return view('livewire.project.shared.secret-manager-links', [
            'selectedToken' => $this->selectedToken,
            'filteredKeys' => $this->filteredKeys,
        ]);
    }
}
