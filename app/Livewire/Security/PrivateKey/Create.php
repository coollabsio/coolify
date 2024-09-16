<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Livewire\Component;
use phpseclib3\Crypt\PublicKeyLoader;

class Create extends Component
{
    use WithRateLimiting;

    public string $name;

    public string $value;

    public ?string $from = null;

    public ?string $description = null;

    public ?string $publicKey = null;

    protected $rules = [
        'name' => 'required|string',
        'value' => 'required|string',
    ];

    protected $validationAttributes = [
        'name' => 'name',
        'value' => 'private Key',
    ];

    public function generateNewRSAKey()
    {
        try {
            $this->rateLimit(10);
            $this->name = generate_random_name();
            $this->description = 'Created by Coolify';
            ['private' => $this->value, 'public' => $this->publicKey] = generateSSHKey();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generateNewEDKey()
    {
        try {
            $this->rateLimit(10);
            $this->name = generate_random_name();
            $this->description = 'Created by Coolify';
            ['private' => $this->value, 'public' => $this->publicKey] = generateSSHKey('ed25519');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updated($updateProperty)
    {
        if ($updateProperty === 'value') {
            try {
                $key = PublicKeyLoader::load($this->$updateProperty);
                $this->publicKey = $key->getPublicKey()->toString('OpenSSH', ['comment' => '']);
            } catch (\Throwable $e) {
                $this->publicKey = '';
                $this->addError('value', 'Invalid private key');
            }
        }
        $this->validateOnly($updateProperty);
    }

    public function createPrivateKey()
    {
        $this->validate([
            'name' => 'required|string',
            'value' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        PublicKeyLoader::load($value);
                    } catch (\Throwable $e) {
                        $fail('The private key is invalid.');
                    }
                },
            ],
        ]);

        try {
            $this->value = trim($this->value);
            if (! str_ends_with($this->value, "\n")) {
                $this->value .= "\n";
            }
            $private_key = PrivateKey::create([
                'name' => $this->name,
                'description' => $this->description,
                'private_key' => $this->value,
                'team_id' => currentTeam()->id,
            ]);
            if ($this->from === 'server') {
                return redirect()->route('dashboard');
            }

            return redirect()->route('security.private-key.show', ['private_key_uuid' => $private_key->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
