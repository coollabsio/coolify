<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ImageScanNotificationState;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageScanNotificationState>
 */
class ImageScanNotificationStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'resource_type' => (new Application)->getMorphClass(),
            'resource_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'team_id' => Team::factory(),
            'notified_ids' => [],
            'notified_candidates' => [],
            'is_baseline' => false,
        ];
    }
}
