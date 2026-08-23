<?php

namespace App\Livewire\Security;

use App\Models\IntegrationToken;
use App\Services\IntegrationTokenValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public function mount(): void
    {
        $this->authorize('create', IntegrationToken::class);
    }

    public function updatedProvider(): void
    {
        if ($this->provider === 'cloudflare') {
            $this->capabilities = ['dns'];
            $this->metadata = [];
        } else {
            $this->capabilities = ['secrets'];
            $this->metadata = $this->provider === 'infisical'
                ? ['base_url' => 'https://app.infisical.com']
                : [];
        }
    }

    protected function rules(): array
    {
        $allowedCapability = $this->provider === 'cloudflare' ? 'dns' : 'secrets';

        $rules = [
            'provider' => ['required', 'in:cloudflare,doppler,infisical,vault'],
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'in:'.$allowedCapability],
        ];

        if ($this->provider === 'infisical') {
            $rules['metadata.base_url'] = ['required', 'url'];
            $rules['metadata.client_id'] = ['required', 'string'];
        }

        if ($this->provider === 'doppler') {
            $rules['token'][] = 'regex:/^dp\.(st|sa)\./';
        }

        if ($this->provider === 'vault') {
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
            'token.regex' => 'Use a Doppler service token (dp.st.*) or service account token (dp.sa.*).',
        ];
    }

    public function addToken(IntegrationTokenValidator $validator): void
    {
        $validated = $this->validate();
        $metadata = array_filter(data_get($validated, 'metadata', []), fn ($value) => filled($value));

        try {
            if (! $validator->validate($validated['provider'], $validated['token'], $validated['capabilities'], $metadata)) {
                $this->dispatch('error', $validator->errorMessage($validated['provider']));

                return;
            }

            IntegrationToken::query()->create([
                'provider' => $validated['provider'],
                'name' => $validated['name'],
                'token' => $validated['token'],
                'capabilities' => $validated['capabilities'],
                'metadata' => $metadata ?: null,
                'team_id' => currentTeam()->id,
            ]);

            $this->reset(['name', 'token']);
            $this->dispatch('integrationTokenAdded')->to(IntegrationTokens::class);

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
