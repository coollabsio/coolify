<?php

namespace Database\Factories;

use App\Models\DnsProviderZone;
use App\Models\ManagedDnsRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class ManagedDnsRecordFactory extends Factory
{
    protected $model = ManagedDnsRecord::class;

    public function definition(): array
    {
        return [
            'dns_provider_zone_id' => DnsProviderZone::factory(),
            'integration_token_id' => fn (array $attributes) => DnsProviderZone::query()->findOrFail($attributes['dns_provider_zone_id'])->integration_token_id,
            'team_id' => fn (array $attributes) => DnsProviderZone::query()->findOrFail($attributes['dns_provider_zone_id'])->integrationToken->team_id,
            'provider_record_id' => fake()->uuid(), 'type' => 'A', 'name' => fake()->domainName(), 'content' => fake()->ipv4(),
        ];
    }
}
