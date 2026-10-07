<?php

namespace Database\Factories;

use App\Models\IntegrationToken;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationTokenFactory extends Factory
{
    protected $model = IntegrationToken::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(), 'provider' => 'cloudflare', 'name' => fake()->words(2, true),
            'token' => fake()->sha256(), 'capabilities' => ['dns'],
        ];
    }
}
