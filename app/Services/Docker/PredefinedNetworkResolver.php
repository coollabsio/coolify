<?php

namespace App\Services\Docker;

use App\Models\DockerNetwork;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PredefinedNetworkResolver
{
    public function resolve(Model $resource): string
    {
        $selected = data_get($resource, 'settings.predefined_network')
            ?? data_get($resource, 'predefined_network');
        $serverId = data_get($resource, 'destination.server_id');
        $fallback = data_get($resource, 'destination.network');
        $networkName = filled($selected) ? $selected : $fallback;

        if (blank($networkName) || blank($serverId)) {
            throw new RuntimeException('Predefined network configuration is invalid.');
        }

        if (filled($selected)) {
            $network = DockerNetwork::query()
                ->byServer((int) $serverId)
                ->where('docker_network_name', $networkName)
                ->where('is_active', true)
                ->first();

            if (! $network) {
                throw new RuntimeException("Selected predefined network '{$networkName}' does not exist on the resource server.");
            }
        }

        return $networkName;
    }
}
