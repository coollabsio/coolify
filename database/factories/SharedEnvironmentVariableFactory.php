<?php

namespace Database\Factories;

use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedEnvironmentVariable>
 */
class SharedEnvironmentVariableFactory extends Factory
{
    protected $model = SharedEnvironmentVariable::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->regexify('[A-Z]{6}'),
            'value' => fake()->word(),
            'type' => 'team',
            'team_id' => 1,
        ];
    }
}
