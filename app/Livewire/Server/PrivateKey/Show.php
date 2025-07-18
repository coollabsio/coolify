<?php

namespace App\Livewire\Server\PrivateKey;

use App\Models\PrivateKey;
use App\Models\Server;
use Livewire\Component;

class Show extends Component
{
    public Server $server;

    public $privateKeys = [];

    public $parameters = [];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->privateKeys = PrivateKey::ownedByCurrentTeam()->get()->where('is_git_related', false);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
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
            ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection(justCheckingNewKey: true);
            if ($uptime) {
                $this->dispatch('success', 'Private key updated successfully.');
            } else {
                throw new \Exception($error);
            }
        } catch (\Exception $e) {
            $this->server->update(['private_key_id' => $originalPrivateKeyId]);
            $this->server->validateConnection();
            $this->dispatch('error', $e->getMessage());
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
        }
    }

    public function render()
    {
        return view('livewire.server.private-key.show');
    }
}
