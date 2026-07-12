<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public function generatePrivateKey(string $type)
    {
        try {
            $this->authorize('create', PrivateKey::class);

            if (! in_array($type, ['ed25519', 'rsa'], true)) {
                $this->dispatch('error', 'Invalid private key type.');

                return;
            }

            $keyData = PrivateKey::generateNewKeyPair($type);
            $privateKey = PrivateKey::createAndStore([
                'name' => $keyData['name'],
                'description' => $keyData['description'],
                'private_key' => $keyData['private_key'],
                'team_id' => currentTeam()->id,
            ]);

            return redirectRoute($this, 'security.private-key.show', ['private_key_uuid' => $privateKey->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $privateKeys = PrivateKey::ownedByCurrentTeam(['name', 'uuid', 'is_git_related', 'description', 'team_id'])->get();

        return view('livewire.security.private-key.index', [
            'privateKeys' => $privateKeys,
        ])->layout('components.layout');
    }

    public function cleanupUnusedKeys()
    {
        try {
            $this->authorize('create', PrivateKey::class);
            PrivateKey::cleanupUnusedKeys();
            $this->dispatch('success', 'Unused keys have been cleaned up.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
