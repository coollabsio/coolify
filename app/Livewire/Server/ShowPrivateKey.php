<?php

namespace App\Livewire\Server;

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
            $oldPrivateKeyId = $this->server->private_key_id;
            refresh_server_connection($this->server->privateKey);
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
            return handleError($e, $this);
        }
    }

    public function checkConnection()
    {
        try {
            $uptime = $this->server->validateConnection();
            if ($uptime) {
                $this->dispatch('success', 'Server is reachable.');
            } else {
                $this->dispatch('error', 'Server is not reachable.<br>Please validate your configuration and connection. See this <a target="_blank" class="underline" href="https://coolify.io/docs/configuration#openssh-server">documentation</a>.');
                return;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }
}
