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
            'wildcard_domain' => 'coolify.io',
            'is_https_forced' => false,
            'is_registration_enabled' => true,
        ]);
    }
}
