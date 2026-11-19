<?php

namespace Database\Factories;

use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageScan>
 */
class ImageScanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'image_id' => 'sha256:'.fake()->sha256(),
            'resource_type' => (new Application)->getMorphClass(),
            'resource_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'image_ref' => 'nginx:1.27',
            'status' => ImageScanStatus::Completed,
            'builds_image' => false,
            'scanned_at' => now(),
        ];
    }
}
