<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class ExecuteContainerCommand extends Component
{
    public $selected_container = 'default';

    public $container;

    public Collection $containers;

    public $parameters;

    public $resource;

    public string $type;

    public Server $server;

    public Collection $servers;

    public bool $hasShell = true;

    protected $rules = [
        'server' => 'required',
        'container' => 'required',
        'command' => 'required',
    ];

    public function mount()
    {
        if (! auth()->user()->isAdmin()) {
            abort(403);
        }
        $this->parameters = get_route_parameters();
        $this->containers = collect();
        $this->servers = collect();
        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            foreach ($this->resource->additional_servers as $server) {
                if ($server->isFunctional()) {
                    $this->servers = $this->servers->push($server);
                }
            }
            $this->loadContainers();
        } elseif (data_get($this->parameters, 'database_uuid')) {
            $this->type = 'database';
            $resource = getResourceByUuid($this->parameters['database_uuid'], data_get(auth()->user()->currentTeam(), 'id'));
            if (is_null($resource)) {
                abort(404);
            }
            $this->resource = $resource;
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            $this->loadContainers();
        } elseif (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
            }
            $this->loadContainers();
        } elseif (data_get($this->parameters, 'server_uuid')) {
            $this->type = 'server';
            $this->resource = Server::where('uuid', $this->parameters['server_uuid'])->firstOrFail();
            $this->server = $this->resource;
        }
    }

    public function loadContainers()
    {
        foreach ($this->servers as $server) {
            if (data_get($this->parameters, 'application_uuid')) {
                if ($server->isSwarm()) {
                    $containers = collect([
                        [
                            'Names' => $this->resource->uuid.'_'.$this->resource->uuid,
                        ],
                    ]);
                } else {
                    $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
                }
                foreach ($containers as $container) {
                    // if container state is running
                    if (data_get($container, 'State') === 'running') {
                        $payload = [
                            'server' => $server,
                            'container' => $container,
                        ];
                        $this->containers = $this->containers->push($payload);
                    }
                }
            } elseif (data_get($this->parameters, 'database_uuid')) {
                if ($this->resource->isRunning()) {
                    $this->containers = $this->containers->push([
                        'server' => $server,
                        'container' => [
                            'Names' => $this->resource->uuid,
                        ],
                    ]);
                }
            } elseif (data_get($this->parameters, 'service_uuid')) {
                $this->resource->applications()->get()->each(function ($application) {
                    if ($application->isRunning()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($application, 'name').'-'.data_get($this->resource, 'uuid'),
                            ],
                        ]);
                    }
                });
                $this->resource->databases()->get()->each(function ($database) {
                    if ($database->isRunning()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($database, 'name').'-'.data_get($this->resource, 'uuid'),
                            ],
                        ]);
                    }
                });
            }
        }
        if ($this->containers->count() > 0) {
            $this->container = $this->containers->first();
        }
        if ($this->containers->count() === 1) {
            $this->selected_container = data_get($this->containers->first(), 'container.Names');
        }
    }

    private function checkShellAvailability(Server $server, string $container): bool
    {
        $escapedContainer = escapeshellarg($container);
        try {
            instant_remote_process([
                "docker exec {$escapedContainer} bash -c 'exit 0' 2>/dev/null || ".
                "docker exec {$escapedContainer} sh -c 'exit 0' 2>/dev/null",
            ], $server);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[On('connectToServer')]
    public function connectToServer()
    {
        try {
            if ($this->server->isForceDisabled()) {
                throw new \RuntimeException('Server is disabled.');
            }
            $this->hasShell = true;
            $this->dispatch(
                'send-terminal-command',
                false,
                data_get($this->server, 'name'),
                data_get($this->server, 'uuid')
            );
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    #[On('connectToContainer')]
    public function connectToContainer()
    {
        if ($this->selected_container === 'default') {
            $this->dispatch('error', 'Please select a container.');

            return;
        }
        try {
            // Validate container name format
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $this->selected_container)) {
                throw new \InvalidArgumentException('Invalid container name format');
            }

            // Verify container exists in our allowed list
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                throw new \RuntimeException('Container not found.');
            }

            // Verify server ownership and status
            $server = data_get($container, 'server');
            if (! $server || ! $server instanceof Server) {
                throw new \RuntimeException('Invalid server configuration.');
            }

            if ($server->isForceDisabled()) {
                throw new \RuntimeException('Server is disabled.');
            }

            // Additional ownership verification based on resource type
            $resourceServer = match ($this->type) {
                'application' => $this->resource->destination->server,
                'database' => $this->resource->destination->server,
                'service' => $this->resource->server,
                default => throw new \RuntimeException('Invalid resource type.')
            };

            if ($server->id !== $resourceServer->id && ! $this->resource->additional_servers->contains('id', $server->id)) {
                throw new \RuntimeException('Server ownership verification failed.');
            }

            $this->hasShell = $this->checkShellAvailability($server, data_get($container, 'container.Names'));
            if (! $this->hasShell) {
                return;
            }

            $this->dispatch(
                'send-terminal-command',
                true,
                data_get($container, 'container.Names'),
                data_get($container, 'server.uuid')
            );
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.execute-container-command');
    }
}
