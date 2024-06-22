<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Visus\Cuid2\Cuid2;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GithubApp>
 */
class GithubAppFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) new Cuid2(7),
            'name' => $this->faker->name,
            'api_url' => $this->faker->url,
            'html_url' => $this->faker->url,
            'team_id' => 0,
            'custom_user' => $this->faker->userName,
            'custom_port' => $this->faker->randomNumber(2),
        ];
    }

    public function publicGitHub(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Github Public',
                'api_url' => 'https://api.github.com',
                'html_url' => 'https://github.com',
                'custom_user' => 'git',
                'custom_port' => 22,
                'is_public' => true,
            ];
        });
    }
}
