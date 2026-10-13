<?php

namespace Database\Factories;

use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'destination_type' => StandaloneDocker::class,
            'destination_id' => 1,
            'environment_id' => 1,
            'docker_compose_raw' => 'version: "3"',
        ];
    }
}
