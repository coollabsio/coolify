<?php

namespace Database\Factories;

use App\Models\CloudProviderToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudProviderToken>
 */
class CloudProviderTokenFactory extends Factory
{
    protected $model = CloudProviderToken::class;

    public function definition(): array
    {
        return [
            'team_id' => 1,
            'provider' => 'hetzner',
            'token' => fake()->sha256(),
            'name' => fake()->words(2, true),
        ];
    }
}
