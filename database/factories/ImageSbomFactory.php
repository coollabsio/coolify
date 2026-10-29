<?php

namespace Database\Factories;

use App\Models\ImageSbom;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageSbom>
 */
class ImageSbomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'image_id' => 'sha256:'.fake()->sha256(),
            'platform' => 'linux/amd64',
            'packages' => [],
            'packages_truncated' => false,
            'last_used_at' => now(),
        ];
    }
}
