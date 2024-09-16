<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Livewire\Component;
use App\Models\PrivateKey;

class ShowPrivateKey extends Component
{
    public Server $server;

    public $privateKeys;

    public $parameters;

    public function setPrivateKey($privateKeyId)
    {
        try {
            $privateKey = PrivateKey::findOrFail($privateKeyId);
            $this->server->update(['private_key_id' => $privateKeyId]);
            $privateKey->storeInFileSystem();
            
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Private key updated and stored in the file system.',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to update private key: ' . $e->getMessage(),
            ]);
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
                $this->dispatch('error', 'Server is not reachable.<br>Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help.');

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
