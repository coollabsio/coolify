<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Change extends Component
{
    public string $private_key_uuid;
    public PrivateKey $private_key;

    protected $rules = [
        'private_key.name' => 'required|string',
        'private_key.description' => 'nullable|string',
        'private_key.private_key' => 'required|string'
    ];
    public function mount()
    {
        $this->private_key = PrivateKey::where('uuid', $this->private_key_uuid)->first();
    }
    public function delete($private_key_uuid)
    {
        PrivateKey::where('uuid', $private_key_uuid)->delete();
        session('currentTeam')->privateKeys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
        redirect()->route('dashboard');
    }
    public function changePrivateKey()
    {
        try {
            $this->private_key->private_key = trim($this->private_key->private_key);
            if (!str_ends_with($this->private_key->private_key, "\n")) {
                $this->private_key->private_key .= "\n";
            }
            $this->private_key->save();
            session('currentTeam')->privateKeys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
        } catch (\Exception $e) {
            $this->addError('private_key_value', $e->getMessage());
        }
    }
}
