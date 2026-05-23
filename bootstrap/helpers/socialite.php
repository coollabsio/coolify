<?php

use App\Models\OauthSetting;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GitlabProvider;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\Config;

function get_socialite_provider(string $provider)
{
    $oauth_setting = OauthSetting::firstWhere('provider', $provider);

    if (! filled($oauth_setting->redirect_uri)) {
        $oauth_setting->update(['redirect_uri' => route('auth.callback', $provider)]);
    }

    if ($provider === 'azure') {
        $azureConfig = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            ['tenant' => $oauth_setting->tenant],
        );

        return Socialite::driver('azure')->setConfig($azureConfig);
    }

    if (in_array($provider, ['authentik', 'clerk', 'oidc', 'zitadel'], true)) {
        $additionalProviderConfig = ['base_url' => $oauth_setting->base_url];

        if ($provider === 'oidc') {
            $additionalProviderConfig = array_filter([
                ...$additionalProviderConfig,
                'scopes' => config('services.oidc.scopes'),
                'verify_jwt' => config('services.oidc.verify_jwt') ? true : null,
                'jwt_public_key' => config('services.oidc.jwt_public_key'),
            ], fn ($value) => ! in_array($value, [null, '', []], true));
        }

        $config = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            $additionalProviderConfig,
        );

        return Socialite::driver($provider)->setConfig($config);
    }

    if ($provider == 'google') {
        $googleConfig = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri
        );

        return Socialite::driver('google')
            ->setConfig($googleConfig)
            ->with(['hd' => $oauth_setting->tenant]);
    }

    $config = [
        'client_id' => $oauth_setting->client_id,
        'client_secret' => $oauth_setting->client_secret,
        'redirect' => $oauth_setting->redirect_uri,
    ];

    $provider_class_map = [
        'bitbucket' => BitbucketProvider::class,
        'discord' => Provider::class,
        'github' => GithubProvider::class,
        'gitlab' => GitlabProvider::class,
        'infomaniak' => SocialiteProviders\Infomaniak\Provider::class,
    ];

    $socialite = Socialite::buildProvider(
        $provider_class_map[$provider],
        $config
    );

    if ($provider == 'gitlab' && ! empty($oauth_setting->base_url)) {
        $socialite->setHost($oauth_setting->base_url);
    }

    return $socialite;
}
