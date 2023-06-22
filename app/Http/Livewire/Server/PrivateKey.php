<?php

namespace App\Http\Livewire\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Masmerise\Toaster\Toaster;

class PrivateKey extends Component
{
    public Server $server;
    public $privateKeys;
    public $parameters;

    public function checkConnection()
    {
        try {
            $uptime = instant_remote_process(['uptime'], $this->server);
            if ($uptime) {
                Toaster::success('Server is reachable with this private key.');
            }
        } catch (\Exception $e) {
            return general_error_handler(customErrorMessage: "Server is not reachable. Reason: {$e->getMessage()}", that: $this);
        }
    }
    public function setPrivateKey($private_key_id)
    {
        $this->server->update([
            'private_key_id' => $private_key_id
        ]);

        // Delete the old ssh mux file to force a new one to be created
        Storage::disk('ssh-mux')->delete($this->server->muxFilename());
        $this->server->refresh();
        $this->checkConnection();
    }
    public function mount()
    {
        $this->parameters = getRouteParameters();
    }
}
