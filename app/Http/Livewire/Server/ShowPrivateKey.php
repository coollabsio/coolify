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

    public function checkConnection($install = false)
    {
        try {
            $uptime = $this->server->validateConnection();
            if ($uptime) {
                $install && $this->emit('success', 'Server is reachable.');
            } else {
                $install && $this->emit('error', 'Server is not reachable. Please check your connection and private key configuration.');
                return;
            }
            $dockerInstalled = $this->server->validateDockerEngine();
            if ($dockerInstalled) {
                $install && $this->emit('success', 'Docker Engine is installed.<br> Checking version.');
            } else {
                $install && $this->installDocker();
                return;
            }
            $dockerVersion = $this->server->validateDockerEngineVersion();
            if ($dockerVersion) {
                $install && $this->emit('success', 'Docker Engine version is 23+.');
            } else {
                $install && $this->installDocker();
                return;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->emit('proxyStatusUpdated');
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }
}
