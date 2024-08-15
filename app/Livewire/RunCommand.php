<?php

namespace App\Livewire;

use App\Models\Server;
use Livewire\Attributes\On;
use Livewire\Component;

class RunCommand extends Component
{
    public $selected_uuid;

    public $servers = [];

    public $containers = [];

    public function mount($servers)
    {
        $this->servers = $servers;
        $this->selected_uuid = $servers[0]->uuid;
        $this->containers = $this->getAllActiveContainers();
    }

    private function getAllActiveContainers()
    {
        return Server::all()->flatMap(function ($server) {
            if (! $server->isFunctional()) {
                return [];
            }

            return $server->definedResources()
                ->filter(function ($resource) {
                    $status = method_exists($resource, 'realStatus') ? $resource->realStatus() : (method_exists($resource, 'status') ? $resource->status() : 'exited');
                    return str_starts_with($status, 'running:');
                })
                ->map(function ($resource) use ($server) {
                    $container_name = $resource->uuid;

                    if (class_basename($resource) === 'Application' || class_basename($resource) === 'Service') {
                        if ($server->isSwarm()) {
                            $container_name = $resource->uuid.'_'.$resource->uuid;
                        } else {
                            $current_containers = getCurrentApplicationContainerStatus($server, $resource->id, includePullrequests: true);
                            $container_name = data_get($current_containers->first(), 'Names');
                        }
                    }

                    return [
                        'name' => $resource->name,
                        'connection_name' => $container_name,
                        'uuid' => $resource->uuid,
                        'status' => $resource->status,
                        'server' => $server,
                        'server_uuid' => $server->uuid,
                    ];
                });
        });
    }

    #[On('connectToContainer')]
    public function connectToContainer()
    {
        $container = collect($this->containers)->firstWhere('uuid', $this->selected_uuid);

        $this->dispatch('send-terminal-command',
            isset($container),
            $container['connection_name'] ?? $this->selected_uuid,
            $container['server_uuid'] ?? $this->selected_uuid
        );
    }
}
