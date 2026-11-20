<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public ?string $selectedPrivateKeyUuid = null;

    public function getListeners(): array
    {
        return [
            'securityResourceChanged' => '$refresh',
            'privateKeyCreated' => 'refreshResources',
            'privateKeyDeleted' => 'refreshResources',
            'privateKeyUpdated' => 'refreshResources',
            'modalClosed' => 'closeEditor',
        ];
    }

    public function openEditor(string $privateKeyUuid): void
    {
        $privateKey = PrivateKey::ownedByCurrentTeam()->whereUuid($privateKeyUuid)->firstOrFail();
        $this->authorize('view', $privateKey);

        $this->selectedPrivateKeyUuid = $privateKey->uuid;
    }

    public function closeEditor(): void
    {
        $this->selectedPrivateKeyUuid = null;
    }

    public function refreshResources(): void
    {
        $this->closeEditor();
        $this->dispatch('close-modal');
    }

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

            $this->dispatch('success', 'Private key generated successfully.');
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
