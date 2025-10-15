<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $value = '';

    public ?string $from = null;

    public ?string $description = null;

    public ?string $publicKey = null;

    public bool $modal_mode = false;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'value' => 'required|string',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'value.required' => 'The Private Key field is required.',
                'value.string' => 'The Private Key must be a valid string.',
            ]
        );
    }

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
            $this->authorize('create', PrivateKey::class);
            $privateKey = PrivateKey::createAndStore([
                'name' => $this->name,
                'description' => $this->description,
                'private_key' => trim($this->value)."\n",
                'team_id' => currentTeam()->id,
            ]);

            // If in modal mode, dispatch event and don't redirect
            if ($this->modal_mode) {
                $this->dispatch('privateKeyCreated', keyId: $privateKey->id);
                $this->dispatch('success', 'Private key created successfully.');

                return;
            }

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
