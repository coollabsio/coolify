<?php

use App\Models\OauthSetting;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GitlabProvider;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Infomaniak\Provider as InfomaniakProvider;
use SocialiteProviders\Manager\Config;

function get_socialite_provider(string $provider)
{
    $oauth_setting = OauthSetting::firstWhere('provider', $provider);

    if (! filled($oauth_setting->redirect_uri)) {
        $oauth_setting->update(['redirect_uri' => route('auth.callback', $provider)]);
    }

    if ($provider === 'azure') {
        $azure_config = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            ['tenant' => $oauth_setting->tenant],
        );

        return Socialite::driver('azure')->setConfig($azure_config);
    }

    if ($provider == 'authentik' || $provider == 'clerk') {
        $authentik_clerk_config = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            ['base_url' => $oauth_setting->base_url],
        );

        return Socialite::driver($provider)->setConfig($authentik_clerk_config);
    }

    if ($provider == 'oidc') {
        $oidc_scopes = collect(preg_split('/[\s,]+/', (string) $oauth_setting->scopes, -1, PREG_SPLIT_NO_EMPTY))
            ->filter()
            ->values()
            ->all();

        $oidc_config = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            ['base_url' => rtrim((string) $oauth_setting->base_url, '/')],
        );

        $socialite = Socialite::driver('oidc')->setConfig($oidc_config);

        if (! empty($oidc_scopes)) {
            $socialite->scopes($oidc_scopes);
        }

        return $socialite;
    }

    if ($provider == 'zitadel') {
        $zitadel_config = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri,
            ['base_url' => $oauth_setting->base_url],
        );

        return Socialite::driver('zitadel')->setConfig($zitadel_config);
    }

    if ($provider == 'google') {
        $google_config = new Config(
            $oauth_setting->client_id,
            $oauth_setting->client_secret,
            $oauth_setting->redirect_uri
        );

        return Socialite::driver('google')
            ->setConfig($google_config)
            ->with(['hd' => $oauth_setting->tenant]);
    }

    $config = [
        'client_id' => $oauth_setting->client_id,
        'client_secret' => $oauth_setting->client_secret,
        'redirect' => $oauth_setting->redirect_uri,
    ];

    $provider_class_map = [
        'bitbucket' => BitbucketProvider::class,
        'discord' => DiscordProvider::class,
        'github' => GithubProvider::class,
        'gitlab' => GitlabProvider::class,
        'infomaniak' => InfomaniakProvider::class,
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
