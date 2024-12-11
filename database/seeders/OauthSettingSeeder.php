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
        $providers = [
            'azure',
            'bitbucket',
            'github',
            'gitlab',
            'google',
            'authentik',
        ];

        foreach ($providers as $provider) {
            OauthSetting::updateOrCreate(
                ['provider' => $provider]
            );
        }
    }
}
