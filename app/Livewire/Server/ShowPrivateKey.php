<?php

namespace App\Livewire\Server;

use App\Models\PrivateKey;
use App\Models\Server;
use Livewire\Component;

class ShowPrivateKey extends Component
{
    public Server $server;

    public $privateKeys;

    public $parameters;

    public function setPrivateKey($privateKeyId)
    {
        try {
            $privateKey = PrivateKey::findOrFail($privateKeyId);
            $this->server->update(['private_key_id' => $privateKey->id]);
            $this->server->refresh();
            $this->dispatch('success', 'Private key updated successfully.');
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to update private key: '.$e->getMessage());
        }
    }

    public function checkConnection()
    {
        try {
            ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
            if ($uptime) {
                $this->dispatch('success', 'Server is reachable.');
            } else {
                ray($error);
                $this->dispatch('error', 'Server is not reachable.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help.<br><br>Error: '.$error);

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
