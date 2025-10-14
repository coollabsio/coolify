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
