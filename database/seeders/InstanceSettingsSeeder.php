<?php

namespace Database\Seeders;

use App\Models\InstanceSettings;
use Illuminate\Database\Seeder;

class InstanceSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        InstanceSettings::create([
            'id' => 0,
            'is_registration_enabled' => true,
            'extra_attributes' => [
                'smtp_test_recipients' => 'test@example.com,test2@example.com',
                'smtp_host' => 'coolify-mail',
                'smtp_port' => 1025,
                'smtp_from_address' => 'hi@localhost.com',
                'smtp_from_name' => 'Coolify',
            ]
        ]);
    }
}
