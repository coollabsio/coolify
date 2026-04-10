<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Upload extends Component
{
    public Server $server;

    public array $servers = [];

    public array $containers = [];

    public function mount(string $server_uuid): void
    {
        Gate::authorize('canAccessTerminal');

        $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->servers = [
            [
                'uuid' => $this->server->uuid,
                'name' => $this->server->name,
            ],
        ];
        $this->containers = $this->runningContainers();
    }

    public function render()
    {
        return view('livewire.server.upload');
    }

    /**
     * @return array<int, array{name: string, uuid: string, server_uuid: string}>
     */
    private function runningContainers(): array
    {
        if (! $this->server->isFunctional() || ! $this->server->isTerminalEnabled()) {
            return [];
        }

        try {
            return $this->server
                ->loadAllContainers()
                ->map(function (array $container): ?array {
                    $state = data_get_str($container, 'State')->lower();
                    if (! $state->contains('running')) {
                        return null;
                    }

                    $name = data_get($container, 'Names');
                    if (blank($name)) {
                        return null;
                    }

                    return [
                        'name' => $name,
                        'uuid' => $name,
                        'server_uuid' => $this->server->uuid,
                    ];
                })
                ->filter()
                ->sortBy('name', SORT_NATURAL)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
