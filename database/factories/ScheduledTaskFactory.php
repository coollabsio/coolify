<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'command' => 'echo hello',
            'frequency' => '* * * * *',
            'timeout' => 300,
            'enabled' => true,
            'team_id' => Team::factory(),
        ];
    }
}
