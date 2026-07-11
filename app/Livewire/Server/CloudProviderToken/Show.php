<?php

namespace App\Livewire\Server\CloudProviderToken;

use App\Models\CloudProviderToken;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public $cloudProviderTokens = [];

    public $parameters = [];

    public string $provider = 'hetzner';

    public string $providerName = 'Hetzner';

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
            'tokenAdded.hetzner' => 'handleTokenAdded',
        ];
    }

    public function loadTokens()
    {
        $this->provider = $this->server->vultr_instance_id ? 'vultr' : 'hetzner';
        $this->providerName = $this->provider === 'vultr' ? 'Vultr' : 'Hetzner';

        $this->cloudProviderTokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', $this->provider)
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

            auditLog('ui.server.cloud_token_assigned', [
                'team_id' => currentTeam()->id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'cloud_token_uuid' => $ownedToken->uuid,
                'cloud_token_name' => $ownedToken->name,
                'provider' => $ownedToken->provider,
            ]);

            $this->dispatch('success', "{$this->providerName} token updated successfully.");
            $this->dispatch('refreshServerShow');
        } catch (\Exception $e) {
            $this->server->refresh();
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function validateTokenForServer(CloudProviderToken $token): array
    {
        try {
            $endpoint = $token->provider === 'vultr'
                ? 'https://api.vultr.com/v2/account'
                : 'https://api.hetzner.cloud/v1/servers';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token->token,
            ])->timeout(10)->get($endpoint);

            if (! $response->successful()) {
                return [
                    'valid' => false,
                    'error' => 'This token is invalid or has insufficient permissions.',
                ];
            }

            if ($this->server->hetzner_server_id) {
                $serverResponse = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token->token,
                ])->timeout(10)->get("https://api.hetzner.cloud/v1/servers/{$this->server->hetzner_server_id}");

                if (! $serverResponse->successful()) {
                    return [
                        'valid' => false,
                        'error' => 'This token cannot access this server. It may belong to a different Hetzner project.',
                    ];
                }
            }

            if ($this->server->vultr_instance_id) {
                $serverResponse = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token->token,
                ])->timeout(10)->get("https://api.vultr.com/v2/instances/{$this->server->vultr_instance_id}");

                if (! $serverResponse->successful()) {
                    return [
                        'valid' => false,
                        'error' => 'This token cannot access this instance. It may belong to a different Vultr account.',
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
                $this->dispatch('error', "No {$this->providerName} token is associated with this server.");

                return;
            }

            $endpoint = $token->provider === 'vultr'
                ? 'https://api.vultr.com/v2/account'
                : 'https://api.hetzner.cloud/v1/servers';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token->token,
            ])->timeout(10)->get($endpoint);

            if ($response->successful()) {
                $this->dispatch('success', "{$this->providerName} token is valid and working.");
            } else {
                $this->dispatch('error', "{$this->providerName} token is invalid or has insufficient permissions.");
            }

            auditLog('ui.server.cloud_token_validated', [
                'team_id' => currentTeam()->id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'cloud_token_uuid' => $token->uuid,
                'cloud_token_name' => $token->name,
                'provider' => $token->provider,
                'valid' => $response->successful(),
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.cloud-provider-token.show');
    }
}
