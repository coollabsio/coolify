<?php

namespace Database\Factories;

use App\Models\CloudProviderToken;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudProviderToken>
 */
class CloudProviderTokenFactory extends Factory
{
    protected $model = CloudProviderToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => 'hetzner',
            'token' => 'test-cloud-provider-token',
            'name' => fake()->words(3, true),
        ];
    }
}
