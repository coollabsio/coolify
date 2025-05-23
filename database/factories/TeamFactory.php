<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeamFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'description' => $this->faker->sentence(),
            'personal_team' => false,
            'show_boarding' => false,
            'custom_server_limit' => null,
        ];
    }

    /**
     * Indicate that the team is personal.
     *
     * @return $this
     */
    public function personal(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'personal_team' => true,
            ];
        });
    }
}
