<?php

namespace App\Http\Livewire\Server;

use App\Models\PrivateKey as ModelsPrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use LocalStorage;

class PrivateKey extends Component
{
    public $private_keys;
    public $parameters;
    public function setPrivateKey($private_key_id)
    {
        $server = Server::where('uuid', $this->parameters['server_uuid']);
        $server->update([
            'private_key_id' => $private_key_id
        ]);
        // Delete the old ssh mux file to force a new one to be created
        LocalStorage::ssh_mux()->delete("{$server->first()->ip}_{$server->first()->port}_{$server->first()->user}");
        return redirect()->route('server.show', $this->parameters['server_uuid']);
    }
    public function mount()
    {
        $this->parameters = get_parameters();
        $this->private_keys = ModelsPrivateKey::where('team_id', session('currentTeam')->id)->get();
    }
}
