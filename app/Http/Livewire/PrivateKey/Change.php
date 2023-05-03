<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Change extends Component
{
    public $private_keys;

    public $private_key_uuid;
    public $private_key_value;
    public $private_key_name;
    public $private_key_description;
    public function delete($private_key_uuid)
    {
        PrivateKey::where('uuid', $private_key_uuid)->delete();
        session('currentTeam')->privateKeys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
        redirect()->route('dashboard');
    }
    public function changePrivateKey()
    {
        try {
            $this->private_key_value = trim($this->private_key_value);
            if (!str_ends_with($this->private_key_value, "\n")) {
                $this->private_key_value .= "\n";
            }
            PrivateKey::where('uuid', $this->private_key_uuid)->update([
                'name' => $this->private_key_name,
                'description' => $this->private_key_description,
                'private_key' => $this->private_key_value,
            ]);
            session('currentTeam')->privateKeys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
        } catch (\Exception $e) {
            $this->addError('private_key_value', $e->getMessage());
        }
    }
}
