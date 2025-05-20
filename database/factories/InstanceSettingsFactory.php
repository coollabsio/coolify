<?php

namespace Database\Factories;

use App\Models\InstanceSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstanceSettingsFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = InstanceSettings::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 0,
            'is_registration_enabled' => true,
            'smtp_enabled' => true,
            'smtp_host' => 'coolify-mail',
            'smtp_port' => 1025,
            'smtp_from_address' => 'hi@localhost.com',
            'smtp_from_name' => 'Coolify',
        ];
    }
}
