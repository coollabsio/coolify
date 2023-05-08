<?php

namespace App\Http\Livewire\Server;

use App\Models\PrivateKey as ModelsPrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class PrivateKey extends Component
{
    public $private_keys;
    public $parameters;
    public function setPrivateKey($private_key_id)
    {
        Server::where('uuid', $this->parameters['server_uuid'])->update([
            'private_key_id' => $private_key_id
        ]);
        return redirect()->route('server.show', $this->parameters['server_uuid']);
    }
    public function mount()
    {
        $this->parameters = saveParameters();
        $this->private_keys = ModelsPrivateKey::where('team_id', session('currentTeam')->id)->get();
    }
}
