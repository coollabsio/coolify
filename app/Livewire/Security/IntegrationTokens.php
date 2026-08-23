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

        $token->delete();
        $this->loadTokens();
        $this->dispatch('success', 'Integration token deleted successfully.');
    }

    public function render()
    {
        return view('livewire.security.integration-tokens');
    }
}
