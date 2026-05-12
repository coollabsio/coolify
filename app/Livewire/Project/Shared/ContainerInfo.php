<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ContainerInfo extends Component
{
    public $resource;

    public array $containers = [];

    public ?string $error = null;

    public function mount(mixed $resource): void
    {
        $this->resource = $resource;
        $this->loadContainers();
    }

    public function loadContainers(): void
    {
        $this->containers = [];
        $this->error = null;

        try {
            $server = $this->resolveServer();
            if (! $server) {
                $this->error = 'No server found for this resource.';

                return;
            }

            if ($server->isSwarm()) {
                $this->error = 'Container information is currently available for Docker standalone resources.';

                return;
            }

            $labels = $this->dockerFilterLabels();
            if ($labels === []) {
                $this->error = 'Container information is not available for this resource type.';

                return;
            }

            $this->containers = $this->inspectContainersByLabels($server, $labels)
                ->map(fn (array $container) => $this->formatContainer($container))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::error('Failed to load container information: '.$e->getMessage(), [
                'resource_type' => $this->resource?->getMorphClass(),
                'resource_id' => $this->resource?->id,
            ]);

            $this->error = 'Unable to load container information.';
        }
    }

    private function resolveServer(): ?Server
    {
        if ($this->resource instanceof ServiceApplication || $this->resource instanceof ServiceDatabase) {
            return $this->resource->service?->server;
        }

        return data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
    }

    private function dockerFilterLabels(): array
    {
        if ($this->resource->getMorphClass() === Application::class) {
            return ["coolify.applicationId={$this->resource->id}"];
        }

        if ($this->resource->getMorphClass() === Service::class) {
            return ["coolify.serviceId={$this->resource->id}"];
        }

        if ($this->resource->getMorphClass() === ServiceApplication::class) {
            return [
                "coolify.serviceId={$this->resource->service_id}",
                "coolify.service.subType=application",
                "coolify.service.subId={$this->resource->id}",
            ];
        }

        if ($this->resource->getMorphClass() === ServiceDatabase::class) {
            return [
                "coolify.serviceId={$this->resource->service_id}",
                "coolify.service.subType=database",
                "coolify.service.subId={$this->resource->id}",
            ];
        }

        if (method_exists($this->resource, 'type') && str($this->resource->type())->startsWith('standalone-')) {
            return ["com.docker.compose.service={$this->resource->uuid}"];
        }

        return [];
    }

    private function inspectContainersByLabels(Server $server, array $labels): Collection
    {
        $filters = collect($labels)
            ->map(fn (string $label) => '--filter '.escapeshellarg("label={$label}"))
            ->implode(' ');

        $command = "ids=\$(docker container ls -aq {$filters}); if [ -n \"\$ids\" ]; then docker container inspect \$ids --format '{{json .}}'; fi";
        $output = instant_remote_process([$command], $server, false) ?? '';

        return format_docker_command_output_to_json($output)->filter();
    }

    private function formatContainer(array $container): array
    {
        $labels = data_get($container, 'Config.Labels', []);
        $status = data_get($container, 'State.Status', 'unknown');
        $health = data_get($container, 'State.Health.Status');

        return [
            'id' => data_get($container, 'Id'),
            'short_id' => substr((string) data_get($container, 'Id'), 0, 12),
            'name' => ltrim((string) data_get($container, 'Name'), '/'),
            'image' => data_get($container, 'Config.Image') ?? data_get($container, 'Image'),
            'compose_service' => data_get($labels, 'com.docker.compose.service')
                ?? data_get($labels, 'coolify.serviceName')
                ?? data_get($labels, 'coolify.name'),
            'status' => $health ? "{$status}:{$health}" : $status,
            'restart_count' => data_get($container, 'RestartCount', 0),
            'ports' => $this->formatPorts(data_get($container, 'NetworkSettings.Ports') ?? []),
            'networks' => $this->formatNetworks(data_get($container, 'NetworkSettings.Networks') ?? []),
        ];
    }

    private function formatPorts(array $ports): array
    {
        return collect($ports)
            ->flatMap(function ($bindings, string $containerPort) {
                if (blank($bindings)) {
                    return [$containerPort];
                }

                return collect($bindings)->map(function (array $binding) use ($containerPort) {
                    $hostIp = data_get($binding, 'HostIp', '0.0.0.0');
                    $hostPort = data_get($binding, 'HostPort');

                    return "{$hostIp}:{$hostPort}->{$containerPort}";
                });
            })
            ->values()
            ->all();
    }

    private function formatNetworks(array $networks): array
    {
        return collect($networks)
            ->map(fn (array $network, string $name) => [
                'name' => $name,
                'ip_address' => data_get($network, 'IPAddress'),
                'gateway' => data_get($network, 'Gateway'),
            ])
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.project.shared.container-info');
    }
}
