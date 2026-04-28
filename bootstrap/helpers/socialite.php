<?php

use App\Models\OauthSetting;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GitlabProvider;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\Config;

/**
 * Determine whether the OAuth provider asserts that the returned email is verified.
 *
 * Fail-closed: providers that return an explicit boolean must report `true`.
 * Providers whose Socialite implementation only ever returns the IdP's primary
 * verified address (azure / github / bitbucket) are trusted directly.
 *
 * Unknown providers return false so that a future-added provider cannot be used
 * to log in until it is explicitly classified here.
 */
function oauth_email_is_verified(string $provider, $oauthUser): bool
{
    $raw = (array) ($oauthUser->user ?? []);

    $explicitlyVerified = static function (array $raw, array $keys): ?bool {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                $normalized = strtolower($value);
                if ($normalized === 'true' || $normalized === '1') {
                    return true;
                }
                if ($normalized === 'false' || $normalized === '0' || $normalized === '') {
                    return false;
                }
            }
            if (is_int($value)) {
                return $value === 1;
            }
        }

        return null;
    };

    switch ($provider) {
        case 'google':
            return $explicitlyVerified($raw, ['email_verified', 'verified_email']) === true;

        case 'authentik':
        case 'zitadel':
        case 'clerk':
        case 'infomaniak':
            return $explicitlyVerified($raw, ['email_verified']) === true;

        case 'discord':
            return $explicitlyVerified($raw, ['verified', 'email_verified']) === true;

        case 'gitlab':
            $verified = $explicitlyVerified($raw, ['email_verified']);
            if ($verified !== null) {
                return $verified === true;
            }

            return ! empty($raw['confirmed_at'] ?? null);

        case 'azure':
        case 'github':
        case 'bitbucket':
            // Trust the upstream Socialite provider class — it only returns
            // the IdP's primary verified address.
            return true;

        default:
            return false;
    }
}

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
