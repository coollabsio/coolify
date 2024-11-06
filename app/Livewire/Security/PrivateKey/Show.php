<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Show extends Component
{
    public PrivateKey $private_key;

    public $public_key = 'Loading...';

    protected $rules = [
        'private_key.name' => 'required|string',
        'private_key.description' => 'nullable|string',
        'private_key.private_key' => 'required|string',
        'private_key.is_git_related' => 'nullable|boolean',
    ];

    protected $validationAttributes = [
        'private_key.name' => 'name',
        'private_key.description' => 'description',
        'private_key.private_key' => 'private key',
    ];

    public function mount()
    {
        try {
            $this->private_key = PrivateKey::ownedByCurrentTeam(['name', 'description', 'private_key', 'is_git_related'])->whereUuid(request()->private_key_uuid)->firstOrFail();
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function loadPublicKey()
    {
        $this->public_key = $this->private_key->getPublicKey();
        if ($this->public_key === 'Error loading private key') {
            $this->dispatch('error', 'Failed to load public key. The private key may be invalid.');
        }
    }

    public function delete()
    {
        try {
            $this->private_key->safeDelete();
            currentTeam()->privateKeys = PrivateKey::where('team_id', currentTeam()->id)->get();

            return redirect()->route('security.private-key.index');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changePrivateKey()
    {
        try {
            $this->private_key->updatePrivateKey([
                'private_key' => formatPrivateKey($this->private_key->private_key),
            ]);
            refresh_server_connection($this->private_key);
            $this->dispatch('success', 'Private key updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
