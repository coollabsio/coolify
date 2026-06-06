<?php

namespace App\Services\Docker;

use App\Models\DockerNetwork;
use App\Models\Server;
use Illuminate\Support\Collection;

class DestinationNetworkSynchronizer
{
    public function sync(Server $server): Collection
    {
        $destinations = $server->standaloneDockers()->get();

        foreach ($destinations as $destination) {
            $network = DockerNetwork::query()
                ->byServer($server)
                ->byName($destination->network)
                ->first();

            if (! $network) {
                $network = DockerNetwork::create([
                    'server_id' => $server->id,
                    'display_name' => $destination->network,
                    'docker_network_name' => $destination->network,
                    'available_during_creation' => true,
                    'is_active' => true,
                ]);
            } elseif (! $network->available_during_creation) {
                $network->update(['available_during_creation' => true]);
            }
        }

        return DockerNetwork::query()
            ->byServer($server)
            ->whereIn('docker_network_name', $destinations->pluck('network'))
            ->get();
    }
}
