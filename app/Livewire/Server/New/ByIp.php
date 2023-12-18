<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
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


    protected $rules = [
        'name' => 'required|string',
        'description' => 'nullable|string',
        'ip' => 'required',
        'user' => 'required|string',
        'port' => 'required|integer',
        'is_swarm_manager' => 'required|boolean',
        'is_swarm_worker' => 'required|boolean',
    ];
    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'ip' => 'IP Address/Domain',
        'user' => 'User',
        'port' => 'Port',
        'is_swarm_manager' => 'Swarm Manager',
        'is_swarm_worker' => 'Swarm Worker',
    ];

    public function mount()
    {
        $this->name = generate_random_name();
        $this->private_key_id = $this->private_keys->first()->id;
    }

    public function setPrivateKey(string $private_key_id)
    {
        $this->private_key_id = $private_key_id;
    }

    public function instantSave()
    {
        $this->dispatch('success', 'Application settings updated!');
    }

    public function submit()
    {
        $this->validate();
        try {
            if (is_null($this->private_key_id)) {
                return $this->dispatch('error', 'You must select a private key');
            }
            $server = Server::create([
                'name' => $this->name,
                'description' => $this->description,
                'ip' => $this->ip,
                'user' => $this->user,
                'port' => $this->port,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
                'proxy' => [
                    "type" => ProxyTypes::TRAEFIK_V2->value,
                    "status" => ProxyStatus::EXITED->value,
                ],
            ]);
            $server->settings->is_swarm_manager = $this->is_swarm_manager;
            $server->settings->is_swarm_worker = $this->is_swarm_worker;
            $server->settings->save();
            $server->addInitialNetwork();
            return $this->redirectRoute('server.show', $server->uuid, navigate: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
