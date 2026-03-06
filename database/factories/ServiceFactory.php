<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'destination_type' => \App\Models\StandaloneDocker::class,
            'destination_id' => 1,
            'environment_id' => 1,
            'docker_compose_raw' => 'version: "3"',
        ];
    }
}
