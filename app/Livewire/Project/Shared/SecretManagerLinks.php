<?php

namespace App\Livewire\Project\Shared;

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Models\IntegrationToken;
use App\Services\SecretManagers\ResourceSecretManager;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
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
        $this->availableTokens = $this->secretManager()->availableTokens($this->resource);

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

        return $this->secretManager()->findToken($this->resource, $this->integration_token_uuid);
    }

    private function secretManager(): ResourceSecretManager
    {
        return app(ResourceSecretManager::class);
    }

    protected function rules(): array
    {
        $rules = [
            'integration_token_uuid' => ['required', 'string'],
        ];

        $token = $this->selectedToken;
        if ($token !== null) {
            $rules += $this->secretManager()->settingsRules($token);
        }

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

            $validated = $this->validate($this->tokenChangeRules());
            $this->secretManager()->link($this->resource, $token, data_get($validated, 'settings', []), 'ui');

            $this->resetKeys();
            $this->loadData();
            $this->dispatch('success', 'Secret manager source saved. References resolve at the next deployment.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * The settings rules of the selected provider, with the fields still optional: a token is
     * saved before the user fills in its settings.
     *
     * @return array<string, array<int, string>>
     */
    private function tokenChangeRules(): array
    {
        return collect($this->rules())
            ->map(fn (array $rules, string $field): array => str_starts_with($field, 'settings.')
                ? array_map(fn (string $rule): string => $rule === 'required' ? 'nullable' : $rule, $rules)
                : $rules)
            ->all();
    }

    /**
     * Auto-save of the provider-specific settings fields (called on blur).
     */
    public function saveSettings(): void
    {
        $this->authorize('update', $this->resource);
        $this->authorize('useTeamCredentials', $this->resource);

        if (! $this->link) {
            return;
        }

        $validated = $this->validate();

        try {

            $settings = array_filter(data_get($validated, 'settings', []), fn ($value) => filled($value));

            $this->link->update(['settings' => $settings ?: null]);
            $this->secretManager()->audit('ui', $this->resource, 'settings_updated');
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
            $this->secretManager()->unlink($this->resource, 'ui');
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

            $this->keys = $this->secretManager()->keyNames($this->resource, $this->link, 'ui');
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

            try {
                $this->secretManager()->addReference($this->resource, $key, 'ui');
            } catch (ValidationException $e) {
                $this->dispatch('error', $e->validator->errors()->first('key'));

                return;
            }

            $this->dispatch('refreshEnvs')->to(All::class);
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

            ['imported' => $imported, 'skipped' => $skipped] = $this->secretManager()->importAll($this->resource, $this->link, 'ui');

            $this->dispatch('refreshEnvs')->to(All::class);
            $this->dispatch('success', $this->importResultMessage($imported, $skipped));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @param  list<string>  $imported
     * @param  list<string>  $skipped
     */
    private function importResultMessage(array $imported, array $skipped): string
    {
        if ($imported === [] && $skipped === []) {
            return 'All remote keys already exist as variables.';
        }

        $message = $imported === []
            ? 'No keys imported.'
            : 'Imported '.count($imported).' keys as {{vault.KEY}} references.';

        if ($skipped === []) {
            return $message;
        }

        $shownNames = array_map(fn (string $key): string => e($key), array_slice($skipped, 0, 10));
        $moreCount = count($skipped) - count($shownNames);
        $names = implode(', ', $shownNames).($moreCount > 0 ? " and {$moreCount} more" : '');

        return $message.' '.(count($skipped) === 1
            ? "Skipped 1 key that is not a valid variable name: {$names}."
            : 'Skipped '.count($skipped)." keys that are not valid variable names: {$names}.");
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
            'invalidKeys' => array_flip(array_filter(
                $this->keys,
                fn (string $key) => ! ValidationPatterns::isValidEnvironmentVariableKey($key),
            )),
        ]);
    }
}
