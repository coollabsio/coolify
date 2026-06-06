<?php

namespace App\Services\Docker;

use App\Models\DockerNetwork;
use App\Models\Server;
use App\Services\Docker\Concerns\ExecutesDockerCommands;
use Closure;
use Illuminate\Support\Collection;

class DockerNetworkScanner
{
    use ExecutesDockerCommands;

    public function __construct(
        private readonly DockerNetworkInspector $normalizer = new DockerNetworkInspector,
        private readonly DockerNetworkClassifier $classifier = new DockerNetworkClassifier,
        private readonly ?Closure $executor = null,
    ) {}

    public function list(Server $server): Collection
    {
        return $this->listNetworkRows($server) ?? collect();
    }

    private function listNetworkRows(Server $server): ?Collection
    {
        $output = $this->executeDocker($server, ["docker network ls --format '{{json .}}'"], $this->executor);

        if ($output === null) {
            return null;
        }

        return collect(explode("\n", (string) $output))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($network) => is_array($network) && filled(data_get($network, 'Name')))
            ->map(fn (array $network) => [
                'docker_network_name' => data_get($network, 'Name'),
                'raw' => $network,
            ])
            ->values();
    }

    public function inspect(Server $server, string $networkName): array
    {
        $decoded = $this->normalizer->rawInspect($server, $networkName, $this->executor);

        if ($decoded === null) {
            return [];
        }

        return $this->normalizer->normalize($decoded);
    }

    public function scan(Server $server): Collection
    {
        return $this->sync($server)->get('networks', collect());
    }

    public function sync(Server $server): Collection
    {
        $result = collect([
            'found' => 0,
            'created' => 0,
            'updated' => 0,
            'marked_inactive' => 0,
            'errors' => [],
            'networks' => collect(),
        ]);

        if (! $server->isFunctional()) {
            return $result->put('errors', ['Server is not functional.']);
        }

        $listedNetworks = $this->listNetworkRows($server);

        if ($listedNetworks === null) {
            return $result->put('errors', ['Unable to list Docker networks.']);
        }

        $foundNames = $listedNetworks->pluck('docker_network_name')->filter()->values();
        $networks = collect();

        foreach ($foundNames as $networkName) {
            $normalized = $this->inspect($server, $networkName);

            if (blank(data_get($normalized, 'docker_network_name'))) {
                $errors = $result->get('errors');
                $errors[] = "Unable to inspect Docker network [{$networkName}].";
                $result->put('errors', $errors);

                continue;
            }

            $classification = $this->classifier->classify($server, $networkName);
            $dockerNetwork = DockerNetwork::byServer($server)
                ->byName($networkName)
                ->first();

            $attributes = array_merge($this->normalizer->toPersistenceArray($normalized), [
                'source_type' => $classification['source_type'],
                'source_id' => $classification['source_id'],
                'network_role' => $classification['network_role'],
                'is_system' => $classification['is_system'],
                'is_active' => true,
            ]);

            if ($dockerNetwork) {
                $dockerNetwork->fill($attributes);

                if (! $dockerNetwork->managed_by_coolify) {
                    $dockerNetwork->managed_by_coolify = $classification['managed_by_coolify'];
                }

                $dockerNetwork->save();
                $result->put('updated', $result->get('updated') + 1);
            } else {
                $dockerNetwork = DockerNetwork::create(array_merge($attributes, [
                    'server_id' => $server->id,
                    'display_name' => $networkName,
                    'docker_network_name' => $networkName,
                    'managed_by_coolify' => $classification['managed_by_coolify'],
                    'external' => $classification['external'],
                ]));
                $result->put('created', $result->get('created') + 1);
            }

            $networks->push($dockerNetwork->refresh());
        }

        $markedInactive = DockerNetwork::byServer($server)
            ->where('is_active', true)
            ->whereNotIn('docker_network_name', $foundNames)
            ->update([
                'is_active' => false,
                'last_inspected_at' => now(),
            ]);

        return $result
            ->put('found', $foundNames->count())
            ->put('marked_inactive', $markedInactive)
            ->put('networks', $networks);
    }
}
