<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\Seeder;

class ApplicationSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $application_1 = Application::find(1)->load(['settings']);
        $application_1->settings->is_debug = false;
        $application_1->settings->save();
    }
}
