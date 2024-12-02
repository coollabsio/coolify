<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ByIp extends Component
{
    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    #[Validate('nullable|integer', as: 'Private Key')]
    public ?int $private_key_id = null;

    #[Validate('nullable|string', as: 'Private Key Name')]
    public $new_private_key_name;

    #[Validate('nullable|string', as: 'Private Key Description')]
    public $new_private_key_description;

    #[Validate('nullable|string', as: 'Private Key Value')]
    public $new_private_key_value;

    #[Validate('required|string', as: 'Name')]
    public string $name;

    #[Validate('nullable|string', as: 'Description')]
    public ?string $description = null;

    #[Validate('required|string', as: 'IP Address/Domain')]
    public string $ip;

    #[Validate('required|string', as: 'User')]
    public string $user = 'root';

    #[Validate('required|integer|between:1,65535', as: 'Port')]
    public int $port = 22;

    #[Validate('required|boolean', as: 'Swarm Manager')]
    public bool $is_swarm_manager = false;

    #[Validate('required|boolean', as: 'Swarm Worker')]
    public bool $is_swarm_worker = false;

    #[Validate('nullable|integer', as: 'Swarm Cluster')]
    public $selected_swarm_cluster = null;

    #[Validate('required|boolean', as: 'Build Server')]
    public bool $is_build_server = false;

    #[Locked]
    public Collection $swarm_managers;

    public function mount()
    {
        $this->name = generate_random_name();
        $this->private_key_id = $this->private_keys->first()?->id;
        $this->swarm_managers = Server::isUsable()->get()->where('settings.is_swarm_manager', true);
        if ($this->swarm_managers->count() > 0) {
            $this->selected_swarm_cluster = $this->swarm_managers->first()->id;
        }
    }

    public function setPrivateKey(string $private_key_id)
    {
        $this->private_key_id = $private_key_id;
    }

    public function instantSave()
    {
        // $this->dispatch('success', 'Application settings updated!');
    }

    public function submit()
    {
        $this->validate();
        try {
            if (Server::where('team_id', currentTeam()->id)
                ->where('ip', $this->ip)
                ->exists()) {
                return $this->dispatch('error', 'This IP/Domain is already in use by another server in your team.');
            }

            if (is_null($this->private_key_id)) {
                return $this->dispatch('error', 'You must select a private key');
            }
            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }
            $payload = [
                'name' => $this->name,
                'description' => $this->description,
                'ip' => $this->ip,
                'user' => $this->user,
                'port' => $this->port,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
            ];
            if ($this->is_swarm_worker) {
                $payload['swarm_cluster'] = $this->selected_swarm_cluster;
            }
            if ($this->is_build_server) {
                data_forget($payload, 'proxy');
            }
            $server = Server::create($payload);
            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();
            if ($this->is_build_server) {
                $this->is_swarm_manager = false;
                $this->is_swarm_worker = false;
            } else {
                $server->settings->is_swarm_manager = $this->is_swarm_manager;
                $server->settings->is_swarm_worker = $this->is_swarm_worker;
            }
            $server->settings->is_build_server = $this->is_build_server;
            $server->settings->save();

            return redirect()->route('server.show', $server->uuid);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
