<?php

namespace Database\Factories;

use App\Enums\ConfigRevisionStatus;
use App\Models\EnvironmentConfigRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentConfigRevisionFactory extends Factory
{
    protected $model = EnvironmentConfigRevision::class;

    public function definition(): array
    {
        return [
            'trigger' => 'api',
            'status' => ConfigRevisionStatus::Planned,
            'file_hash' => fake()->sha256(),
            'state_hash' => fake()->sha256(),
            'format_version' => 1,
            'plan' => ['changes' => []],
        ];
    }
}
