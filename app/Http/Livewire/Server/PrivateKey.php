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
            ['uptime' => $uptime, 'dockerVersion' => $dockerVersion] = validateServer($this->server);
            if ($uptime) {
                Toaster::success('Server is reachable with this private key.');
            }
            if ($dockerVersion) {
                Toaster::success('Server is usable for Coolify.');
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
        refreshPrivateKey($this->server->privateKey);
        $this->server->refresh();
        $this->checkConnection();
    }
    public function mount()
    {
        $this->parameters = getRouteParameters();
    }
}
