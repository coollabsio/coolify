<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class Create extends Component
{
    public string $name;
    public string|null $description = null;
    public string $value;
    public string $currentRoute;

    public function mount()
    {
        $this->currentRoute = Route::current()->uri();
    }
    public function createPrivateKey()
    {
        $this->value = trim($this->value);
        if (!str_ends_with($this->value, "\n")) {
            $this->value .= "\n";
        }
        $new_private_key = PrivateKey::create([
            'name' => $this->name,
            'description' => $this->description,
            'private_key' => $this->value,
            'team_id' => session('currentTeam')->id
        ]);
        redirect()->route('server.new');
    }
}
