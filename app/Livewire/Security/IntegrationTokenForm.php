<?php

namespace App\Livewire\Security;

use App\Models\IntegrationToken;
use App\Services\CloudflareTokenValidator;
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

    public function mount(): void
    {
        $this->authorize('create', IntegrationToken::class);
    }

    protected function rules(): array
    {
        return [
            'provider' => ['required', 'in:cloudflare'],
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
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

    public function addToken(CloudflareTokenValidator $validator): void
    {
        $validated = $this->validate();

        try {
            if (! $validator->validate($validated['token'], $validated['capabilities'])) {
                $this->dispatch('error', 'The token could not access the selected Cloudflare capabilities. Check its permissions and zone resources.');

                return;
            }

            IntegrationToken::query()->create([
                ...$validated,
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
