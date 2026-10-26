<?php

namespace Database\Factories;

use App\Enums\ImageScanAlertLevel;
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
            'alert_level' => null,
            'gate_enabled' => null,
            'on_scan_error' => null,
        ];
    }

    public function concrete(): static
    {
        return $this->state([
            'alert_level' => ImageScanAlertLevel::Critical,
            'gate_enabled' => false,
            'on_scan_error' => ImageScanOnError::Deploy,
        ]);
    }
}
