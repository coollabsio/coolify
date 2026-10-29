<?php

namespace App\Livewire\Security\AgeKey;

use App\Models\AgeKey;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $publicKey = '';

    public ?string $description = null;

    public bool $modal_mode = false;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'publicKey' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! AgeKey::isValidPublicKey($value)) {
                    $fail('Invalid age public key. It should look like age1...');
                }
            }],
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'publicKey.required' => 'The Public Key field is required.',
                'publicKey.string' => 'The Public Key must be a valid string.',
            ]
        );
    }

    public function updated($property)
    {
        if ($property === 'publicKey' && filled($this->publicKey) && ! AgeKey::isValidPublicKey($this->publicKey)) {
            $this->addError('publicKey', 'Invalid age public key. It should look like age1...');
        }
    }

    public function createAgeKey()
    {
        $this->validate();

        try {
            $this->authorize('create', AgeKey::class);

            $ageKey = AgeKey::create([
                'name' => $this->name,
                'description' => $this->description,
                'public_key' => trim($this->publicKey),
                'team_id' => currentTeam()->id,
            ]);

            if ($this->modal_mode) {
                $this->dispatch('ageKeyCreated', keyId: $ageKey->id);
                $this->dispatch('success', 'Age key added successfully.');

                return;
            }

            return redirectRoute($this, 'security.age-key.show', ['age_key_uuid' => $ageKey->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
