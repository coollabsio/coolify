<?php

namespace Database\Seeders;

use App\Models\OauthSetting;
use Illuminate\Database\Seeder;

class OauthSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        OauthSetting::firstOrCreate([
            'id' => 0,
            'provider' => 'azure',
        ]);
        OauthSetting::firstOrCreate([
            'id' => 1,
            'provider' => 'bitbucket',
        ]);
        OauthSetting::firstOrCreate([
            'id' => 2,
            'provider' => 'github',
        ]);
        OauthSetting::firstOrCreate([
            'id' => 3,
            'provider' => 'gitlab',
        ]);
        OauthSetting::firstOrCreate([
            'id' => 4,
            'provider' => 'google',
        ]);
    }
}
