<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Create extends Component
{
    public $private_key_value;
    public $private_key_name;
    public $private_key_description;
    public function createPrivateKey()
    {
        $this->private_key_value = trim($this->private_key_value);
        if (!str_ends_with($this->private_key_value, "\n")) {
            $this->private_key_value .= "\n";
        }
        PrivateKey::create([
            'name' => $this->private_key_name,
            'description' => $this->private_key_description,
            'private_key' => $this->private_key_value,
            'team_id' => session('currentTeam')->id
        ]);
        session('currentTeam')->privateKeys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
    }
}
