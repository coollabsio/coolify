<?php

namespace Database\Factories;

use App\Models\OriginApp;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OriginApp>
 */
class OriginAppFactory extends Factory
{
    protected $model = OriginApp::class;

    public function definition(): array
    {
        $keyPair = generateOriginKeyPair();

        return [
            'name' => fake()->words(2, true),
            'public_key' => $keyPair['public_key'],
            'private_key' => $keyPair['private_key'],
            'team_id' => Team::factory(),
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'app_id' => 'app_'.fake()->regexify('[a-z0-9]{26}'),
            'installation_id' => 'i_'.fake()->regexify('[a-z0-9]{26}'),
        ]);
    }
}
