<?php

namespace App\Livewire\Server\CloudProviderToken;

use App\Models\CloudProviderToken;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public $cloudProviderTokens = [];

    public $parameters = [];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->loadTokens();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getListeners()
    {
        return [
            'tokenAdded' => 'handleTokenAdded',
        ];
    }

    public function loadTokens()
    {
        $this->cloudProviderTokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->get();
    }

    public function handleTokenAdded($tokenId)
    {
        $this->loadTokens();
    }

    public function setCloudProviderToken($tokenId)
    {
        $ownedToken = CloudProviderToken::ownedByCurrentTeam()->find($tokenId);
        if (is_null($ownedToken)) {
            $this->dispatch('error', 'You are not allowed to use this token.');

            return;
        }
        try {
            $this->authorize('update', $this->server);

            // Validate the token works and can access this specific server
            $validationResult = $this->validateTokenForServer($ownedToken);
            if (! $validationResult['valid']) {
                $this->dispatch('error', $validationResult['error']);

                return;
            }

            $this->server->cloudProviderToken()->associate($ownedToken);
            $this->server->save();
            $this->dispatch('success', 'Hetzner token updated successfully.');
            $this->dispatch('refreshServerShow');
        } catch (\Exception $e) {
            $this->server->refresh();
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function validateTokenForServer(CloudProviderToken $token): array
    {
        try {
            // First, validate the token itself
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer '.$token->token,
            ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

            if (! $response->successful()) {
                return [
                    'valid' => false,
                    'error' => 'This token is invalid or has insufficient permissions.',
                ];
            }

            // Check if this token can access the specific Hetzner server
            if ($this->server->hetzner_server_id) {
                $serverResponse = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => 'Bearer '.$token->token,
                ])->timeout(10)->get("https://api.hetzner.cloud/v1/servers/{$this->server->hetzner_server_id}");

                if (! $serverResponse->successful()) {
                    return [
                        'valid' => false,
                        'error' => 'This token cannot access this server. It may belong to a different Hetzner project.',
                    ];
                }
            }

            return ['valid' => true];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'error' => 'Failed to validate token: '.$e->getMessage(),
            ];
        }
    }

    public function validateToken()
    {
        try {
            $token = $this->server->cloudProviderToken;
            if (! $token) {
                $this->dispatch('error', 'No Hetzner token is associated with this server.');

                return;
            }

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer '.$token->token,
            ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

            if ($response->successful()) {
                $this->dispatch('success', 'Hetzner token is valid and working.');
            } else {
                $this->dispatch('error', 'Hetzner token is invalid or has insufficient permissions.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.cloud-provider-token.show');
    }
}
