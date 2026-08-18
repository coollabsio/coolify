<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'project_id' => 1,
        ];
    }
}
