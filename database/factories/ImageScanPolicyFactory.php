<?php

namespace Database\Factories;

use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use App\Models\ImageScanPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageScanPolicy>
 */
class ImageScanPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scope_type' => null,
            'scope_id' => null,
            'gate_mode' => null,
            'on_scan_error' => null,
        ];
    }

    public function concrete(): static
    {
        return $this->state([
            'gate_mode' => ImageScanGateMode::Off,
            'on_scan_error' => ImageScanOnError::Deploy,
        ]);
    }
}
