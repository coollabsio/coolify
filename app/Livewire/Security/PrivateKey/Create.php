<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';

    public string $value = '';

    public ?string $from = null;

    public ?string $description = null;

    public ?string $publicKey = null;

    protected $rules = [
        'name' => 'required|string',
        'value' => 'required|string',
    ];

    public function generateNewRSAKey()
    {
        $this->generateNewKey('rsa');
    }

    public function generateNewEDKey()
    {
        $this->generateNewKey('ed25519');
    }

    private function generateNewKey($type)
    {
        $keyData = PrivateKey::generateNewKeyPair($type);
        $this->setKeyData($keyData);
    }

    public function updated($property)
    {
        if ($property === 'value') {
            $this->validatePrivateKey();
        }
    }

    public function createPrivateKey()
    {
        $this->validate();

        try {
            $privateKey = PrivateKey::createAndStore([
                'name' => $this->name,
                'description' => $this->description,
                'private_key' => trim($this->value)."\n",
                'team_id' => currentTeam()->id,
            ]);

            return $this->redirectAfterCreation($privateKey);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function setKeyData(array $keyData)
    {
        $this->name = $keyData['name'];
        $this->description = $keyData['description'];
        $this->value = $keyData['private_key'];
        $this->publicKey = $keyData['public_key'];
    }

    private function validatePrivateKey()
    {
        $validationResult = PrivateKey::validateAndExtractPublicKey($this->value);
        $this->publicKey = $validationResult['publicKey'];

        if (! $validationResult['isValid']) {
            $this->addError('value', 'Invalid private key');
        }
    }

    private function redirectAfterCreation(PrivateKey $privateKey)
    {
        return $this->from === 'server'
            ? redirect()->route('dashboard')
            : redirect()->route('security.private-key.show', ['private_key_uuid' => $privateKey->uuid]);
    }
}
