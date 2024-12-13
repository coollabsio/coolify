<?php

use App\Models\OauthSetting;
use Laravel\Socialite\Facades\Socialite;

function get_socialite_provider(string $provider)
{
    $oauth_setting = OauthSetting::firstWhere('provider', $provider);

    if ($provider === 'azure') {
        $azure_config = new \SocialiteProviders\Manager\Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            ['tenant' => $oauth_setting->tenant],
        );

        return Socialite::driver('azure')->setConfig($azure_config);
    }

    if ($provider == 'authentik') {
        $authentik_config = new \SocialiteProviders\Manager\Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            ['base_url' => $oauth_setting->base_url],
        );

        return Socialite::driver('authentik')->setConfig($authentik_config);
    }

    $config = [
        'client_id' => $oauth_setting->client_id,
        'client_secret' => $oauth_setting->client_secret,
        'redirect' => $oauth_setting->redirect_uri,
    ];

    $provider_class_map = [
        'bitbucket' => \Laravel\Socialite\Two\BitbucketProvider::class,
        'github' => \Laravel\Socialite\Two\GithubProvider::class,
        'gitlab' => \Laravel\Socialite\Two\GitlabProvider::class,
        'google' => \Laravel\Socialite\Two\GoogleProvider::class,
    ];

    return Socialite::buildProvider(
        $provider_class_map[$provider],
        $config
    );
}
