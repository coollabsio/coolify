<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'destination_id' => 1,
            'git_repository' => fake()->url(),
            'git_branch' => fake()->word(),
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => 1,
            'destination_id' => 1,
        ];
    }
}
