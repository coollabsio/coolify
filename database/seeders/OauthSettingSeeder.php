<?php

namespace Database\Seeders;

use App\Models\OauthSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class OauthSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            $providers = collect([
                'azure',
                'bitbucket',
                'github',
                'gitlab',
                'google',
                'authentik',
                'infomaniak',
            ]);

            $isOauthSeeded = OauthSetting::count() > 0;

            // We changed how providers are defined in the database, so we authentik does not exists, we need to recreate all of the auth providers
            // Before authentik was a provider, providers started with 0 id

            $isOauthAuthentik = OauthSetting::where('provider', 'authentik')->exists();
            if (! $isOauthSeeded || $isOauthAuthentik) {
                foreach ($providers as $provider) {
                    OauthSetting::updateOrCreate([
                        'provider' => $provider,
                    ]);
                }

                return;
            }

            $allProviders = OauthSetting::all();
            $notFoundProviders = $providers->diff($allProviders->pluck('provider'));

            $allProviders->each(function ($provider) {
                $provider->delete();
            });
            $allProviders->each(function ($provider) {
                $provider = new OauthSetting;
                $provider->provider = $provider->provider;
                unset($provider->id);
                $provider->save();
            });

            foreach ($notFoundProviders as $provider) {
                OauthSetting::create([
                    'provider' => $provider,
                ]);
            }

        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
