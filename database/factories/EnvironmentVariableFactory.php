<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvironmentVariable>
 */
class EnvironmentVariableFactory extends Factory
{
    protected $model = EnvironmentVariable::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->regexify('[A-Z]{6}'),
            'value' => fake()->word(),
            'is_preview' => false,
            'resourceable_type' => Application::class,
            'resourceable_id' => 1,
        ];
    }
}
