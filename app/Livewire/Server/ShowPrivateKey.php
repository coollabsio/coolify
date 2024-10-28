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

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function setPrivateKey($privateKeyId)
    {
        $ownedPrivateKey = PrivateKey::ownedByCurrentTeam()->find($privateKeyId);
        if (is_null($ownedPrivateKey)) {
            $this->dispatch('error', 'You are not allowed to use this private key.');

            return;
        }

        $originalPrivateKeyId = $this->server->getOriginal('private_key_id');
        try {
            $this->server->update(['private_key_id' => $privateKeyId]);
            ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
            if ($uptime) {
                $this->dispatch('success', 'Private key updated successfully.');
            } else {
                throw new \Exception('Server is not reachable.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help.<br><br>Error: '.$error);
            }
        } catch (\Exception $e) {
            $this->server->update(['private_key_id' => $originalPrivateKeyId]);
            $this->server->validateConnection();
            $this->dispatch('error', 'Failed to update private key: '.$e->getMessage());
        } finally {
            $this->dispatch('refreshServerShow');
            $this->server->refresh();
        }
    }

    public function checkConnection()
    {
        try {
            ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
            if ($uptime) {
                $this->dispatch('success', 'Server is reachable.');
            } else {
                $this->dispatch('error', 'Server is not reachable.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help.<br><br>Error: '.$error);

                return;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refreshServerShow');
            $this->server->refresh();
        }
    }
}
