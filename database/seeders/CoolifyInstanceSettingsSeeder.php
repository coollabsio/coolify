<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\CoolifyInstanceSettings;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\Seeder;

class CoolifyInstanceSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CoolifyInstanceSettings::create([
            'id' => 1,
            'wildcard_domain' => 'coolify.io',
            'is_https_forced' => false,
            'is_registration_enabled' => true,
        ]);
    }
}
