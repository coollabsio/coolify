<?php

namespace App\Livewire;

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
        return collect($this->servers)->flatMap(function ($server) {
            if (! $server->isFunctional()) {
                return [];
            }

            return $server->definedResources()
                ->filter(function ($resource) {
                    $status = method_exists($resource, 'realStatus') ? $resource->realStatus() : (method_exists($resource, 'status') ? $resource->status() : 'exited');

                    return str_starts_with($status, 'running:');
                })
                ->map(function ($resource) use ($server) {
                    if (isDev()) {
                        if (data_get($resource, 'name') === 'coolify-db') {
                            $container_name = 'coolify-db';

                            return [
                                'name' => $resource->name,
                                'connection_name' => $container_name,
                                'uuid' => $resource->uuid,
                                'status' => 'running',
                                'server' => $server,
                                'server_uuid' => $server->uuid,
                            ];
                        }
                    }

                    if (class_basename($resource) === 'Application') {
                        if (! $server->isSwarm()) {
                            $current_containers = getCurrentApplicationContainerStatus($server, $resource->id, includePullrequests: true);
                        }
                        $status = $resource->status;
                    } elseif (class_basename($resource) === 'Service') {
                        $current_containers = getCurrentServiceContainerStatus($server, $resource->id);
                        $status = $resource->status();
                    } else {
                        $status = getContainerStatus($server, $resource->uuid);
                        if ($status === 'running') {
                            $current_containers = collect([
                                'Names' => $resource->name,
                            ]);
                        }
                    }
                    if ($server->isSwarm()) {
                        $container_name = $resource->uuid.'_'.$resource->uuid;
                    } else {
                        $container_name = data_get($current_containers->first(), 'Names');
                    }

                    return [
                        'name' => $resource->name,
                        'connection_name' => $container_name,
                        'uuid' => $resource->uuid,
                        'status' => $status,
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
