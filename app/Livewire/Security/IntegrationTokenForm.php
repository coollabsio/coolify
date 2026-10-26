<?php

namespace App\Livewire\Security;

use App\Models\IntegrationToken;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\IntegrationTokenValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
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
        $rules = [
            'provider' => ['required', 'in:'.implode(',', array_keys(IntegrationToken::PROVIDER_NAMES))],
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
            'capabilities' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && in_array('tunnel', $value, true) && ! in_array('dns', $value, true)) {
                    $fail('Cloudflare Tunnel also needs the DNS capability.');
                }
            }],
            'capabilities.*' => ['required', 'in:'.implode(',', IntegrationToken::allowedCapabilities($this->provider))],
            'automaticDns' => ['boolean'],
            'proxiedDns' => ['boolean'],
        ];

        if ($this->provider === 'infisical') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.client_id'] = ['required', 'string'];
            $rules['metadata.timeout'] = ['nullable', 'integer', 'min:1', 'max:300'];
        }

        if ($this->provider === 'doppler') {
            $rules['token'][] = 'regex:/^dp\.(st|sa)\./';
        }

        if ($this->provider === 'vault') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.namespace'] = ['nullable', 'string'];
            $rules['metadata.timeout'] = ['nullable', 'integer', 'min:1', 'max:300'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'capabilities.required' => 'Select at least one capability.',
            'capabilities.min' => 'Select at least one capability.',
            'token.regex' => 'Use a Doppler service token (dp.st.*) or service account token (dp.sa.*).',
        ];
    }

    public function addToken(IntegrationTokenValidator $validator, CloudflareDnsProvider $cloudflare): void
    {
        $this->authorize('create', IntegrationToken::class);

        $validated = $this->validate();
        $metadata = array_filter(data_get($validated, 'metadata', []), fn ($value) => filled($value));
        if ($validated['provider'] === 'cloudflare' && ! $validated['automaticDns']) {
            $metadata['automatic_dns'] = false;
        }
        if ($validated['provider'] === 'cloudflare' && $validated['proxiedDns']) {
            $metadata['proxied_dns'] = true;
        }

        try {
            if (! $validator->validate($validated['provider'], $validated['token'], $validated['capabilities'], $metadata)) {
                $this->dispatch('error', $validator->errorMessage($validated['provider']));

                return;
            }

            $integrationToken = DB::transaction(function () use ($validated, $metadata, $cloudflare): IntegrationToken {
                $token = IntegrationToken::query()->create([
                    'provider' => $validated['provider'], 'name' => $validated['name'], 'token' => $validated['token'],
                    'capabilities' => $validated['capabilities'], 'metadata' => $metadata ?: null, 'team_id' => currentTeam()->id,
                ]);
                if ($token->provider === 'cloudflare') {
                    $cloudflare->syncZones($token);
                }

                return $token;
            });

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
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.integration-token-form');
    }
}
