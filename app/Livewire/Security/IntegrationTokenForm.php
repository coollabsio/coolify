<?php

namespace App\Livewire\Security;

use App\Actions\IntegrationToken\CreateIntegrationToken;
use App\Models\IntegrationToken;
use App\Services\IntegrationTokenValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Component;

class IntegrationTokenForm extends Component
{
    use AuthorizesRequests;

    public bool $modal_mode = false;

    public string $provider = 'cloudflare';

    public string $name = '';

    public string $token = '';

    public array $capabilities = ['dns'];

    public array $metadata = [];

    public bool $automaticDns = true;

    public bool $proxiedDns = false;

    public function mount(): void
    {
        $this->authorize('create', IntegrationToken::class);
    }

    public function updatedProvider(): void
    {
        if ($this->provider === 'cloudflare') {
            $this->capabilities = ['dns'];
            $this->metadata = [];
            $this->automaticDns = true;
            $this->proxiedDns = false;
        } else {
            $this->capabilities = ['secrets'];
            $this->metadata = $this->provider === 'infisical'
                ? ['base_url' => 'https://app.infisical.com']
                : [];
        }
    }

    /**
     * Cloudflare Tunnel setup also writes DNS records, so selecting `tunnel` also selects `dns`.
     */
    public function updatedCapabilities(): void
    {
        if (in_array('tunnel', $this->capabilities, true) && ! in_array('dns', $this->capabilities, true)) {
            $this->capabilities[] = 'dns';
        }
    }

    protected function rules(): array
    {
        return CreateIntegrationToken::rules($this->provider) + [
            'automaticDns' => ['boolean'],
            'proxiedDns' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'capabilities.required' => 'Select at least one capability.',
            'capabilities.min' => 'Select at least one capability.',
            'token.regex' => 'Use a Doppler service token (dp.st.*) or service account token (dp.sa.*).',
        ];
    }

    public function addToken(IntegrationTokenValidator $validator): void
    {
        $this->authorize('create', IntegrationToken::class);

        $validated = $this->validate();

        try {
            $integrationToken = CreateIntegrationToken::run(
                currentTeam()->id,
                $validated['provider'],
                $validated['name'],
                $validated['token'],
                $validated['capabilities'],
                data_get($validated, 'metadata', []),
                $validated['automaticDns'],
                $validated['proxiedDns'],
            );

            auditLog('ui.integration_token.created', [
                'team_id' => currentTeam()->id,
                'integration_token_uuid' => $integrationToken->uuid,
                'integration_token_name' => $integrationToken->name,
                'provider' => $integrationToken->provider,
            ]);

            $this->reset(['name', 'token']);
            $this->dispatch('integrationTokenAdded');

            if ($this->modal_mode) {
                $this->dispatch('close-modal');
            }

            $this->dispatch('success', 'Integration token added successfully.');
        } catch (ConnectionException) {
            $this->dispatch('error', $validator->connectionErrorMessage($validated['provider']));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.integration-token-form');
    }
}
