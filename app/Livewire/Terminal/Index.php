<?php

namespace App\Livewire\Terminal;

use App\Models\Server;
use Livewire\Attributes\On;
use Livewire\Component;

class Index extends Component
{
    public $selected_uuid = 'default';

    public $servers = [];

    public $containers = [];

    public bool $isLoadingContainers = true;

    public function mount()
    {
        $this->servers = Server::isReachable()->get()->filter(function ($server) {
            return $server->isTerminalEnabled();
        });
    }

    public function loadContainers()
    {
        try {
            $this->containers = $this->getAllActiveContainers();
        } catch (\Exception $e) {
            return handleError($e, $this);
        } finally {
            $this->isLoadingContainers = false;
        }
    }

    private function getAllActiveContainers()
    {
        return collect($this->servers)->flatMap(function ($server) {
            if (! $server->isFunctional()) {
                return [];
            }

            return $server->loadAllContainers()->map(function ($container) use ($server) {
                $state = data_get_str($container, 'State')->lower();
                if ($state->contains('running')) {
                    return [
                        'name' => data_get($container, 'Names'),
                        'connection_name' => data_get($container, 'Names'),
                        'uuid' => data_get($container, 'Names'),
                        'status' => data_get_str($container, 'State')->lower(),
                        'server' => $server,
                        'server_uuid' => $server->uuid,
                    ];
                }

                return null;
            })->filter();
        })->sortBy('name');
    }

    public function updatedSelectedUuid()
    {
        if ($this->selected_uuid === 'default') {
            // When cleared to default, do nothing (no error message)
            return;
        }
        $this->connectToContainer();
    }

    #[On('connectToContainer')]
    public function connectToContainer()
    {
        if ($this->selected_uuid === 'default') {
            $this->dispatch('error', 'Please select a server or a container.');

            return;
        }

        // Container options encode "{server.uuid}|{container.name}" so duplicate
        // container names across servers (e.g. when Custom Container Name is set
        // on multi-server apps) resolve to the right container. Server-only
        // options remain bare server UUIDs and fall through to server-mode SSH.
        $container = null;
        if (str_contains($this->selected_uuid, '|')) {
            [$serverUuid, $containerName] = explode('|', $this->selected_uuid, 2);
            if ($serverUuid === '' || $containerName === '') {
                $this->dispatch('error', 'Invalid selection.');

                return;
            }
            $container = collect($this->containers)->first(
                fn ($c) => data_get($c, 'server_uuid') === $serverUuid
                    && data_get($c, 'name') === $containerName
            );
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }
        }

        $this->dispatch('send-terminal-command',
            isset($container),
            $container['connection_name'] ?? $this->selected_uuid,
            $container['server_uuid'] ?? $this->selected_uuid
        );
    }

    public function render()
    {
        return view('livewire.terminal.index');
    }
}
