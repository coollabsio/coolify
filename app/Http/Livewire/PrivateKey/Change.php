<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Change extends Component
{
    public PrivateKey $private_key;

    protected $rules = [
        'private_key.name' => 'required|string',
        'private_key.description' => 'nullable|string',
        'private_key.private_key' => 'required|string'
    ];
    protected $validationAttributes = [
        'private_key.name' => 'name',
        'private_key.description' => 'description',
        'private_key.private_key' => 'private key'
    ];
    public function delete()
    {
        try {
            PrivateKey::where('uuid', $this->private_key_uuid)->delete();
            session('currentTeam')->privateKeys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
            redirect()->route('dashboard');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
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
            return general_error_handler(err: $e, that: $this);
        }
    }
}
