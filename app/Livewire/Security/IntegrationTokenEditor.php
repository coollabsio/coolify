<?php

namespace App\Livewire\Security;

use App\Models\IntegrationToken;
use App\Services\IntegrationTokenValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class IntegrationTokenEditor extends Component
{
    use AuthorizesRequests;

    public IntegrationToken $integrationToken;

    public string $name = '';

    public string $newToken = '';

    public array $capabilities = [];

    public array $metadata = [];

    public function mount(string $integration_token_uuid): void
    {
        $this->integrationToken = IntegrationToken::ownedByCurrentTeam()
            ->whereUuid($integration_token_uuid)
            ->firstOrFail();

        $this->authorize('view', $this->integrationToken);

        $this->name = $this->integrationToken->name;
        $this->capabilities = $this->integrationToken->capabilities;
        $this->metadata = $this->integrationToken->metadata ?? [];
    }

    protected function rules(): array
    {
        $allowedCapability = $this->integrationToken->provider === 'cloudflare' ? 'dns' : 'secrets';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'newToken' => ['nullable', 'string'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'in:'.$allowedCapability],
        ];

        if ($this->integrationToken->provider === 'infisical') {
            $rules['metadata.base_url'] = ['required', 'url'];
            $rules['metadata.client_id'] = ['required', 'string'];
        }

        if ($this->integrationToken->provider === 'vault') {
            $rules['metadata.base_url'] = ['required', 'url'];
            $rules['metadata.namespace'] = ['nullable', 'string'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'capabilities.required' => 'Select at least one capability.',
            'capabilities.min' => 'Select at least one capability.',
        ];
    }

    public function save(IntegrationTokenValidator $validator): void
    {
        $this->authorize('update', $this->integrationToken);
        $validated = $this->validate();
        $provider = $this->integrationToken->provider;
        $token = filled($validated['newToken']) ? $validated['newToken'] : $this->integrationToken->token;
        $metadata = array_filter(data_get($validated, 'metadata', []), fn ($value) => filled($value));
        $capabilitiesChanged = collect($validated['capabilities'])->sort()->values()->all()
            !== collect($this->integrationToken->capabilities)->sort()->values()->all();
        $metadataChanged = $metadata != ($this->integrationToken->metadata ?? []);

        try {
            if ((filled($validated['newToken']) || $capabilitiesChanged || $metadataChanged)
                && ! $validator->validate($provider, $token, $validated['capabilities'], $metadata)) {
                $this->dispatch('error', $validator->errorMessage($provider));

                return;
            }

            $updates = [
                'name' => $validated['name'],
                'capabilities' => $validated['capabilities'],
                'metadata' => $metadata ?: null,
            ];

            if (filled($validated['newToken'])) {
                $updates['token'] = $validated['newToken'];
            }

            $this->integrationToken->update($updates);
            $this->newToken = '';

            auditLog('ui.integration_token.updated', [
                'team_id' => currentTeam()->id,
                'integration_token_uuid' => $this->integrationToken->uuid,
                'integration_token_name' => $this->integrationToken->name,
                'provider' => $this->integrationToken->provider,
                'rotated' => array_key_exists('token', $updates),
            ]);

            $this->dispatch(
                'integration-token-updated',
                uuid: $this->integrationToken->uuid,
                name: $this->integrationToken->name,
                capabilities: $this->integrationToken->capabilities,
            );
            $this->dispatch('success', 'Integration token updated successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function delete(string $password = ''): void
    {
        $this->authorize('delete', $this->integrationToken);

        if ($this->integrationToken->secretManagerLinks()->exists()) {
            $this->dispatch('error', 'This token is used by one or more resources as a secret manager source. Remove those links first.');

            return;
        }

        $uuid = $this->integrationToken->uuid;
        $name = $this->integrationToken->name;
        $provider = $this->integrationToken->provider;
        $this->integrationToken->delete();

        auditLog('ui.integration_token.deleted', [
            'team_id' => currentTeam()->id,
            'integration_token_uuid' => $uuid,
            'integration_token_name' => $name,
            'provider' => $provider,
        ]);

        $this->dispatch('integration-token-deleted', uuid: $this->integrationToken->uuid);
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Integration token deleted successfully.');
    }

    public function render()
    {
        return view('livewire.security.integration-token-editor');
    }
}
