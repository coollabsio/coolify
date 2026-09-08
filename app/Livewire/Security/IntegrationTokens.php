<?php

namespace App\Livewire\Security;

use App\Models\IntegrationToken;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

class IntegrationTokens extends Component
{
    use AuthorizesRequests;

    public $tokens;

    public function mount(): void
    {
        $this->authorize('viewAny', IntegrationToken::class);
        $this->loadTokens();
    }

    #[On('integrationTokenAdded')]
    public function loadTokens(): void
    {
        $this->tokens = IntegrationToken::ownedByCurrentTeam()->latest()->get();
    }

    public function deleteToken(int $tokenId, string $password = ''): void
    {
        $token = IntegrationToken::ownedByCurrentTeam()->findOrFail($tokenId);
        $this->authorize('delete', $token);

        if ($token->secretManagerLinks()->exists()) {
            $this->dispatch('error', 'This token is used by one or more resources as a secret manager source. Remove those links first.');

            return;
        }

        $tokenUuid = $token->uuid;
        $tokenName = $token->name;
        $provider = $token->provider;
        $token->delete();
        auditLog('ui.integration_token.deleted', [
            'team_id' => currentTeam()->id,
            'integration_token_uuid' => $tokenUuid,
            'integration_token_name' => $tokenName,
            'provider' => $provider,
        ]);
        $this->loadTokens();
        $this->dispatch('success', 'Integration token deleted successfully.');
    }

    public function render()
    {
        return view('livewire.security.integration-tokens');
    }
}
