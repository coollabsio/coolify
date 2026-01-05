<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public function render()
    {
        $privateKeys = PrivateKey::ownedByCurrentTeam(['name', 'uuid', 'is_git_related', 'description', 'team_id'])->get();

        return view('livewire.security.private-key.index', [
            'privateKeys' => $privateKeys,
        ])->layout('components.layout');
    }

    public function cleanupUnusedKeys()
    {
        $this->authorize('create', PrivateKey::class);
        PrivateKey::cleanupUnusedKeys();
        $this->dispatch('success', 'Unused keys have been cleaned up.');
    }
}
