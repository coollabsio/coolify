<?php

namespace App\Services\Docker;

use App\Enums\DockerNetworkDriver;
use App\Enums\DockerNetworkScope;
use App\Models\Server;
use App\Services\Docker\Concerns\ExecutesDockerCommands;
use Closure;

class DockerNetworkInspector
{
    use ExecutesDockerCommands;

    public function normalize(array $inspect): array
    {
        $raw = $this->firstNetwork($inspect);
        $ipamConfigs = collect(data_get($raw, 'IPAM.Config', []))
            ->filter(fn ($config) => is_array($config))
            ->values();
        $ipamConfig = $ipamConfigs->first() ?? [];

        return [
            'docker_id' => data_get($raw, 'Id'),
            'docker_network_name' => data_get($raw, 'Name'),
            'driver' => data_get($raw, 'Driver', 'unknown') ?: 'unknown',
            'scope' => data_get($raw, 'Scope', 'unknown') ?: 'unknown',
            'subnet' => data_get($ipamConfig, 'Subnet'),
            'gateway' => data_get($ipamConfig, 'Gateway'),
            'ip_range' => data_get($ipamConfig, 'IPRange'),
            'ipam_configs' => $ipamConfigs->all(),
            'aux_addresses' => data_get($ipamConfig, 'AuxiliaryAddresses') ?: [],
            'internal' => (bool) data_get($raw, 'Internal', false),
            'attachable' => (bool) data_get($raw, 'Attachable', true),
            'enable_ipv6' => (bool) data_get($raw, 'EnableIPv6', false),
            'labels' => data_get($raw, 'Labels') ?: [],
            'options' => data_get($raw, 'Options') ?: [],
            'containers' => data_get($raw, 'Containers') ?: [],
            'raw' => $raw,
        ];
    }

    public function rawInspect(Server $server, string $networkName, ?Closure $executor = null): ?array
    {
        $output = $this->executeDocker($server, ['docker network inspect '.escapeshellarg($networkName)], $executor);
        $decoded = json_decode((string) $output, true);

        if ($output === null || ! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    public function toPersistenceArray(array $normalized): array
    {
        return [
            'driver' => DockerNetworkDriver::tryFrom(data_get($normalized, 'driver', 'unknown') ?: 'unknown')?->value
                ?? DockerNetworkDriver::Unknown->value,
            'scope' => DockerNetworkScope::tryFrom(data_get($normalized, 'scope', 'unknown') ?: 'unknown')?->value
                ?? DockerNetworkScope::Unknown->value,
            'subnet' => data_get($normalized, 'subnet'),
            'gateway' => data_get($normalized, 'gateway'),
            'ip_range' => data_get($normalized, 'ip_range'),
            'aux_addresses' => data_get($normalized, 'aux_addresses', []),
            'internal' => data_get($normalized, 'internal', false),
            'attachable' => data_get($normalized, 'attachable', true),
            'enable_ipv6' => data_get($normalized, 'enable_ipv6', false),
            'labels' => data_get($normalized, 'labels', []),
            'options' => data_get($normalized, 'options', []),
            'last_inspected_at' => now(),
            'last_inspect_data' => [
                'docker_id' => data_get($normalized, 'docker_id'),
                'ipam_configs' => data_get($normalized, 'ipam_configs', []),
                'containers' => data_get($normalized, 'containers', []),
                'raw' => data_get($normalized, 'raw', []),
            ],
        ];
    }

    public function firstNetwork(array $inspect): array
    {
        if (array_is_list($inspect) && isset($inspect[0]) && is_array($inspect[0])) {
            return $inspect[0];
        }

        return $inspect;
    }
}
