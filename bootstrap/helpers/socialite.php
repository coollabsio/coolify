<?php

use App\Models\OauthSetting;
use Laravel\Socialite\Facades\Socialite;

function get_socialite_provider(string $provider)
{
    $oauth_setting = OauthSetting::firstWhere('provider', $provider);
    $config = [
        'client_id' => $oauth_setting->client_id,
        'client_secret' => $oauth_setting->client_secret,
        'redirect' => $oauth_setting->redirect_uri,
        'tenant' => $oauth_setting->tenant,
    ];
    $provider_class_map = [
        'azure' => \SocialiteProviders\Azure\Provider::class,
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
