<?php

namespace Database\Factories;

use App\Models\ProxyConfigurationVersion;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyConfigurationVersion>
 */
class ProxyConfigurationVersionFactory extends Factory
{
    public function definition(): array
    {
        $configuration = "services:\n  traefik:\n    container_name: coolify-proxy\n    image: traefik:v3.".fake()->unique()->numberBetween(0, 9999)."\n";

        return [
            'server_id' => Server::factory(),
            'configuration' => $configuration,
            'hash' => ProxyConfigurationVersion::hashOf($configuration),
            'source' => 'ui',
        ];
    }
}
