<?php

namespace App\Http\Livewire\Server;

use App\Models\Server;
use Livewire\Component;

class ShowPrivateKey extends Component
{
    public Server $server;
    public $privateKeys;
    public $parameters;

    public function setPrivateKey($newPrivateKeyId)
    {
        try {
            refresh_server_connection($this->server->privateKey);
            $oldPrivateKeyId = $this->server->private_key_id;
            $this->server->update([
                'private_key_id' => $newPrivateKeyId
            ]);
            $this->server->refresh();
            refresh_server_connection($this->server->privateKey);
            $this->checkConnection();
        } catch (\Throwable $e) {
            $this->server->update([
                'private_key_id' => $oldPrivateKeyId
            ]);
            $this->server->refresh();
            refresh_server_connection($this->server->privateKey);
            return general_error_handler($e, that: $this);
        }
    }

    public function checkConnection()
    {
        try {
            ['uptime' => $uptime, 'dockerVersion' => $dockerVersion] = validateServer($this->server);
            if ($uptime) {
                $this->emit('success', 'Server is reachable with this private key.');
            } else {
                $this->emit('error', 'Server is not reachable with this private key.');
                return;
            }
            if ($dockerVersion) {
                $this->emit('success', 'Server is usable for Coolify.');
            } else {
                $this->emit('error', 'Old (lower than 23) or no Docker version detected. Install Docker Engine on the General tab.');
            }
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }
}
