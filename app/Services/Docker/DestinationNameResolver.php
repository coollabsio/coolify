<?php

namespace App\Services\Docker;

use App\Models\DockerNetwork;

class DestinationNameResolver
{
    public function fromNetwork(DockerNetwork $network): string
    {
        $alias = trim((string) $network->display_name);

        return $alias !== '' ? $alias : $network->docker_network_name;
    }
}
