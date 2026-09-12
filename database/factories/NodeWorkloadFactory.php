<?php

namespace Database\Factories;

use App\Enums\NodeWorkloadDesiredState;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeWorkloadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(3, true),
            'desired_state' => NodeWorkloadDesiredState::RUNNING,
        ];
    }
}
