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
        $providers = collect([
            'azure',
            'bitbucket',
            'github',
            'gitlab',
            'google',
            'authentik',
        ]);

        $isOauthSeeded = OauthSetting::count() > 0;

        // We changed how providers are defined in the database, so we authentik does not exists, we need to recreate all of the auth providers
        // Before authentik was a provider, providers started with 0 id

        $isOauthAuthentik = OauthSetting::where('provider', 'authentik')->exists();
        if ($isOauthSeeded) {
            if (! $isOauthAuthentik) {
                $allProviders = OauthSetting::all();
                $notFoundProviders = $providers->diff($allProviders->pluck('provider'));

                $allProviders->each(function ($provider) use ($providers) {
                    $provider->delete();
                    $providerName = $provider->provider;

                    $foundProvider = $providers->first(function ($provider) use ($providerName) {
                        return $provider === $providerName;
                    });

                    if ($foundProvider) {
                        $newProvder = new OauthSetting;
                        $newProvder = $provider;
                        unset($newProvder->id);
                        $newProvder->save();
                    }
                });

                foreach ($notFoundProviders as $provider) {
                    OauthSetting::create([
                        'provider' => $provider,
                    ]);
                }
            } else {
                foreach ($providers as $provider) {
                    OauthSetting::updateOrCreate([
                        'provider' => $provider,
                    ]);
                }
            }
        } else {
            foreach ($providers as $provider) {
                OauthSetting::updateOrCreate([
                    'provider' => $provider,
                ]);
            }
        }
    }
}
