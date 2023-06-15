<?php

namespace App\Http\Livewire\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class PrivateKey extends Component
{
    public Server $server;
    public $privateKeys;
    public $parameters;
    public function setPrivateKey($private_key_id)
    {
        $this->server->update([
            'private_key_id' => $private_key_id
        ]);

        // Delete the old ssh mux file to force a new one to be created
        Storage::disk('ssh-mux')->delete("{$this->server->first()->ip}_{$this->server->first()->port}_{$this->server->first()->user}");
        $this->server->refresh();
    }
    public function mount()
    {
        $this->parameters = getRouteParameters();
    }
}
