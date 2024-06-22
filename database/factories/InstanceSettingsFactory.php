<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InstanceSettings>
 */
class InstanceSettingsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 0, // For some odd reason, InstanceSettings::get() filters for id 0...
            'is_registration_enabled' => true,
            'is_resale_license_active' => true,
            'smtp_enabled' => true, // Otherwise the EmailChannel will throw an exception
            'smtp_host' => 'mailpit',
            'smtp_from_name' => 'Coolify',
            'smtp_port' => 1025,
            'smtp_from_address' => 'hi@localhost.com',
        ];
    }
}
