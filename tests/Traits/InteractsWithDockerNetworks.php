<?php

namespace Tests\Traits;

use App\Models\DockerNetwork;
use App\Models\Server;
use App\Models\Team;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use App\Services\Docker\DockerNetworkScanner;
use Illuminate\Support\Collection;

trait InteractsWithDockerNetworks
{
    protected function createFunctionalServer(?Team $team = null): Server
    {
        $team ??= Team::factory()->create();

        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->settings()->update([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ]);

        return $server->refresh();
    }

    protected function dockerInspectPayload(string $name, array $overrides = []): string
    {
        return json_encode([array_merge([
            'Name' => $name,
            'Id' => "{$name}-id",
            'Driver' => 'bridge',
            'Scope' => 'local',
            'EnableIPv6' => false,
            'Internal' => false,
            'Attachable' => true,
            'IPAM' => [
                'Config' => [[
                    'Subnet' => '172.20.0.0/16',
                    'Gateway' => '172.20.0.1',
                ]],
            ],
            'Labels' => ['source' => 'test'],
            'Options' => [],
            'Containers' => [],
        ], $overrides)]);
    }

    protected function createCatalogNetwork(Server $server, string $name = 'coolify', array $attrs = []): DockerNetwork
    {
        return DockerNetwork::create(array_merge([
            'server_id' => $server->id,
            'display_name' => $attrs['display_name'] ?? $name,
            'docker_network_name' => $name,
        ], $attrs));
    }

    protected function fakeCatalogRefresher(int &$calls): DockerNetworkCatalogRefresher
    {
        return new DockerNetworkCatalogRefresher(
            new class($calls) extends DockerNetworkScanner
            {
                public function __construct(private int &$calls) {}

                public function sync(Server $server): Collection
                {
                    $this->calls++;

                    return collect([
                        'found' => 1,
                        'created' => 0,
                        'updated' => 0,
                        'removed' => 0,
                        'errors' => [],
                        'networks' => collect(),
                    ]);
                }
            }
        );
    }
}
