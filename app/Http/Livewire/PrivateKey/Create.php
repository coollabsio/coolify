<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class Create extends Component
{
    protected string|null $from = null;
    public string $name;
    public string|null $description = null;
    public string $value;

    public function createPrivateKey()
    {
        $this->value = trim($this->value);
        if (!str_ends_with($this->value, "\n")) {
            $this->value .= "\n";
        }
        $private_key = PrivateKey::create([
            'name' => $this->name,
            'description' => $this->description,
            'private_key' => $this->value,
            'team_id' => session('currentTeam')->id
        ]);
        if ($this->from === 'server') {
            return redirect()->route('server.new');
        }
        return redirect()->route('private-key.show', ['private_key_uuid' => $private_key->uuid]);
    }
}
