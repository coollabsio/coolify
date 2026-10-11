<?php

namespace Database\Factories;

use App\Models\DnsProviderZone;
use App\Models\IntegrationToken;
use Illuminate\Database\Eloquent\Factories\Factory;

class DnsProviderZoneFactory extends Factory
{
    protected $model = DnsProviderZone::class;

    public function definition(): array
    {
        return [
            'integration_token_id' => IntegrationToken::factory(), 'provider_zone_id' => fake()->uuid(),
            'name' => fake()->unique()->domainName(), 'account_id' => fake()->uuid(), 'account_name' => fake()->company(),
        ];
    }
}
