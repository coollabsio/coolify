<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use Livewire\Component;

class ByIp extends Component
{
    public $private_keys;

    public $limit_reached;

    public ?int $private_key_id = null;

    public $new_private_key_name;

    public $new_private_key_description;

    public $new_private_key_value;

    public string $name;

    public ?string $description = null;

    public string $ip;

    public string $user = 'root';

    public int $port = 22;

    public bool $is_swarm_manager = false;

    public bool $is_swarm_worker = false;

    public $selected_swarm_cluster = null;

    public bool $is_build_server = false;

    public $swarm_managers = [];

    protected $rules = [
        'name' => 'required|string',
        'description' => 'nullable|string',
        'ip' => 'required',
        'user' => 'required|string',
        'port' => 'required|integer',
        'is_swarm_manager' => 'required|boolean',
        'is_swarm_worker' => 'required|boolean',
        'is_build_server' => 'required|boolean',
    ];

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'ip' => 'IP Address/Domain',
        'user' => 'User',
        'port' => 'Port',
        'is_swarm_manager' => 'Swarm Manager',
        'is_swarm_worker' => 'Swarm Worker',
        'is_build_server' => 'Build Server',
    ];

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
                'proxy' => [
                    // set default proxy type to traefik v2
                    'type' => ProxyTypes::TRAEFIK->value,
                    'status' => ProxyStatus::EXITED->value,
                ],
            ];
            if ($this->is_swarm_worker) {
                $payload['swarm_cluster'] = $this->selected_swarm_cluster;
            }
            if ($this->is_build_server) {
                data_forget($payload, 'proxy');
            }
            $server = Server::create($payload);
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
