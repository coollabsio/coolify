<?php

namespace Database\Factories;

use App\Models\InfisicalConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class InfisicalConnectionFactory extends Factory
{
    protected $model = InfisicalConnection::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => $this->faker->word(),
            'host' => 'https://app.infisical.com',
            'client_id' => $this->faker->uuid(),
            'client_secret' => $this->faker->sha256(),
        ];
    }
}
