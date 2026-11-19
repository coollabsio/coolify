<?php

namespace Database\Factories;

use App\Models\ImageScanIgnore;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageScanIgnore>
 */
class ImageScanIgnoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'vulnerability_id' => 'CVE-2024-'.fake()->numerify('#####'),
            'reason' => 'Not reachable in this application',
            'expires_at' => null,
        ];
    }
}
