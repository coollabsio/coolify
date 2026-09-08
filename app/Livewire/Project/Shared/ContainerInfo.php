<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ContainerInfo extends Component
{
    use AuthorizesRequests;

    public $resource;

    public array $containers = [];

    public array $availableNetworks = [];

    public array $selectedNetwork = [];

    public bool $loaded = false;

    public function mount()
    {
        $this->authorize('view', $this->resource);
    }

    private function server()
    {
        return $this->resource instanceof Service
            ? $this->resource->server
            : $this->resource->destination->server;
    }

    public function load(): void
    {
        try {
            $server = $this->server();
            if (! $server->isFunctional() || $server->isSwarm()) {
                return;
            }

            $names = match (true) {
                $this->resource instanceof Application => getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true),
                $this->resource instanceof Service => getCurrentServiceContainerStatus($server, $this->resource->id),
                default => getCurrentDatabaseContainerStatus($server, $this->resource->id),
            };
            $names = $names->pluck('Names')->filter()->sort()->values();

            $this->containers = [];
            if ($names->isNotEmpty()) {
                $inspect = instant_remote_process(
                    ["docker container inspect --format '{{json .}}' ".$names->map(fn ($name) => escapeshellarg($name))->implode(' ')],
                    $server,
                    false
                );
                $this->containers = format_docker_command_output_to_json($inspect)
                    ->map(fn ($container) => $this->summarize($container))
                    ->values()
                    ->all();
            }

            $networks = instant_remote_process(["docker network ls --format '{{.Name}}'"], $server, false);
            $this->availableNetworks = str($networks ?? '')->explode("\n")
                ->map(fn ($name) => trim($name))
                ->filter()
                ->reject(fn ($name) => in_array($name, ['bridge', 'host', 'none']))
                ->sort()
                ->values()
                ->all();
            foreach ($this->containers as $container) {
                $others = array_values(array_diff($this->availableNetworks, $container['network_names']));
                $this->selectedNetwork[$container['name']] = $others[0] ?? '';
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        } finally {
            $this->loaded = true;
        }
    }

    private function summarize(array $container): array
    {
        $networks = collect(data_get($container, 'NetworkSettings.Networks', []))
            ->map(fn ($network, $name) => [
                'name' => $name,
                'ipv4' => data_get($network, 'IPAddress') ?: null,
                'ipv6' => data_get($network, 'GlobalIPv6Address') ?: null,
                'gateway' => data_get($network, 'Gateway') ?: null,
                'mac' => data_get($network, 'MacAddress') ?: null,
                'aliases' => collect(data_get($network, 'Aliases', []))->implode(', '),
            ])
            ->values()
            ->all();

        return [
            'id' => data_get($container, 'Id'),
            'short_id' => substr((string) data_get($container, 'Id'), 0, 12),
            'name' => ltrim((string) data_get($container, 'Name'), '/'),
            'image' => data_get($container, 'Config.Image'),
            'image_id' => data_get($container, 'Image'),
            'status' => data_get($container, 'State.Status'),
            'restart_count' => data_get($container, 'RestartCount', 0),
            'created' => $this->formatTime(data_get($container, 'Created')),
            'started' => $this->formatTime(data_get($container, 'State.StartedAt')),
            'networks' => $networks,
            'network_names' => array_column($networks, 'name'),
        ];
    }

    private function formatTime(?string $time): ?string
    {
        if (blank($time) || str_starts_with($time, '0001-')) {
            return null;
        }

        return Carbon::parse($time)->toDateTimeString().' UTC';
    }

    public function connect(string $container): void
    {
        $this->changeNetwork('connect', $container, (string) ($this->selectedNetwork[$container] ?? ''));
    }

    public function disconnect(string $container, string $network): void
    {
        $this->changeNetwork('disconnect', $container, $network);
    }

    private function changeNetwork(string $action, string $container, string $network): void
    {
        try {
            $this->authorize('update', $this->resource);
            $known = collect($this->containers)->firstWhere('name', $container);
            if (! $known || $network === '') {
                throw new \RuntimeException('Unknown container or network.');
            }
            if ($action === 'disconnect' && count($known['network_names']) <= 1) {
                throw new \RuntimeException('A container must stay connected to at least one network.');
            }
            instant_remote_process(
                ["docker network {$action} ".escapeshellarg($network).' '.escapeshellarg($container)],
                $this->server()
            );
            $this->dispatch('success', ucfirst($action).'ed '.$container.($action === 'connect' ? ' to ' : ' from ')."{$network}.", 'This is a runtime change: a redeploy recreates the container with its configured networks.');
            $this->load();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.container-info');
    }
}
