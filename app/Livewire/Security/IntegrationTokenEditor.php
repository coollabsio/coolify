<?php

namespace App\Livewire\Security;

use App\Models\IntegrationToken;
use App\Services\CloudflareTokenValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class IntegrationTokenEditor extends Component
{
    use AuthorizesRequests;

    public IntegrationToken $integrationToken;

    public string $name = '';

    public string $newToken = '';

    public array $capabilities = [];

    public function mount(string $integration_token_uuid): void
    {
        $this->integrationToken = IntegrationToken::ownedByCurrentTeam()
            ->whereUuid($integration_token_uuid)
            ->firstOrFail();

        $this->authorize('view', $this->integrationToken);

        $this->name = $this->integrationToken->name;
        $this->capabilities = $this->integrationToken->capabilities;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'newToken' => ['nullable', 'string'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'in:dns'],
        ];
    }

    protected function messages(): array
    {
        return [
            'capabilities.required' => 'Select at least one capability.',
            'capabilities.min' => 'Select at least one capability.',
        ];
    }

    public function save(CloudflareTokenValidator $validator): void
    {
        $this->authorize('update', $this->integrationToken);
        $validated = $this->validate();
        $token = filled($validated['newToken']) ? $validated['newToken'] : $this->integrationToken->token;
        $capabilitiesChanged = collect($validated['capabilities'])->sort()->values()->all()
            !== collect($this->integrationToken->capabilities)->sort()->values()->all();

        try {
            if ((filled($validated['newToken']) || $capabilitiesChanged)
                && ! $validator->validate($token, $validated['capabilities'])) {
                $this->dispatch('error', 'The token could not access the selected Cloudflare capabilities. Check its permissions and zone resources.');

                return;
            }

            $updates = [
                'name' => $validated['name'],
                'capabilities' => $validated['capabilities'],
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
        $this->integrationToken->delete();

        $this->dispatch('integration-token-deleted', uuid: $this->integrationToken->uuid);
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Integration token deleted successfully.');
    }

    public function render()
    {
        return view('livewire.security.integration-token-editor');
    }
}
