<?php

namespace App\Livewire\Security;

use App\Models\CloudProviderToken;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class CloudProviderTokens extends Component
{
    use AuthorizesRequests;

    public $tokens;

    public function mount()
    {
        $this->authorize('viewAny', CloudProviderToken::class);
        $this->loadTokens();
    }

    public function getListeners()
    {
        return [
            'tokenAdded' => 'loadTokens',
        ];
    }

    public function loadTokens()
    {
        $this->tokens = CloudProviderToken::ownedByCurrentTeam()->get();
    }

    public function validateToken(int $tokenId)
    {
        try {
            $token = CloudProviderToken::ownedByCurrentTeam()->findOrFail($tokenId);
            $this->authorize('view', $token);

            if ($token->provider === 'hetzner') {
                $isValid = $this->validateHetznerToken($token->token);
                if ($isValid) {
                    $this->dispatch('success', 'Hetzner token is valid.');
                } else {
                    $this->dispatch('error', 'Hetzner token validation failed. Please check the token.');
                }
            } elseif ($token->provider === 'digitalocean') {
                $isValid = $this->validateDigitalOceanToken($token->token);
                if ($isValid) {
                    $this->dispatch('success', 'DigitalOcean token is valid.');
                } else {
                    $this->dispatch('error', 'DigitalOcean token validation failed. Please check the token.');
                }
            } else {
                $this->dispatch('error', 'Unknown provider.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function validateHetznerToken(string $token): bool
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(10)
                ->get('https://api.hetzner.cloud/v1/servers?per_page=1');

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function validateDigitalOceanToken(string $token): bool
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(10)
                ->get('https://api.digitalocean.com/v2/account');

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function deleteToken(int $tokenId)
    {
        try {
            $token = CloudProviderToken::ownedByCurrentTeam()->findOrFail($tokenId);
            $this->authorize('delete', $token);

            // Check if any servers are using this token
            if ($token->hasServers()) {
                $serverCount = $token->servers()->count();
                $this->dispatch('error', "Cannot delete this token. It is currently used by {$serverCount} server(s). Please reassign those servers to a different token first.");

                return;
            }

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
