<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['production', 'staging', 'development']),
            'project_id' => 1,
        ];
    }
}
