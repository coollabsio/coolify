<?php

namespace App\Livewire\Security;

use App\Models\CloudProviderToken;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class CloudProviderTokens extends Component
{
    use AuthorizesRequests;

    public $tokens;

    public string $provider = 'hetzner';

    public string $token = '';

    public string $name = '';

    public function mount()
    {
        $this->authorize('viewAny', CloudProviderToken::class);
        $this->loadTokens();
    }

    protected function rules(): array
    {
        return [
            'provider' => 'required|string|in:hetzner,digitalocean',
            'token' => 'required|string',
            'name' => 'required|string|max:255',
        ];
    }

    protected function messages(): array
    {
        return [
            'provider.required' => 'Please select a cloud provider.',
            'provider.in' => 'Invalid cloud provider selected.',
            'token.required' => 'API token is required.',
            'name.required' => 'Token name is required.',
        ];
    }

    public function loadTokens()
    {
        $this->tokens = CloudProviderToken::ownedByCurrentTeam()->get();
    }

    private function validateToken(string $provider, string $token): bool
    {
        try {
            if ($provider === 'hetzner') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

                return $response->successful();
            }

            // Add other providers here in the future
            // if ($provider === 'digitalocean') { ... }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function addNewToken()
    {
        $this->validate();

        try {
            $this->authorize('create', CloudProviderToken::class);

            // Validate the token with the provider's API
            if (! $this->validateToken($this->provider, $this->token)) {
                return $this->dispatch('error', 'Invalid API token. Please check your token and try again.');
            }

            CloudProviderToken::create([
                'team_id' => currentTeam()->id,
                'provider' => $this->provider,
                'token' => $this->token,
                'name' => $this->name,
            ]);

            $this->reset(['token', 'name']);
            $this->loadTokens();

            $this->dispatch('success', 'Cloud provider token added successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteToken(int $tokenId)
    {
        try {
            $token = CloudProviderToken::findOrFail($tokenId);
            $this->authorize('delete', $token);

            $token->delete();
            $this->loadTokens();

            $this->dispatch('success', 'Cloud provider token deleted successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.cloud-provider-tokens');
    }
}
