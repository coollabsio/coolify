<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        $privateKeys = PrivateKey::ownedByCurrentTeam(['name', 'uuid', 'is_git_related', 'description'])->get();

        return view('livewire.security.private-key.index', [
            'privateKeys' => $privateKeys,
        ])->layout('components.layout');
    }

    public function cleanupUnusedKeys()
    {
        PrivateKey::cleanupUnusedKeys();
        $this->dispatch('success', 'Unused keys have been cleaned up.');
    }
}
