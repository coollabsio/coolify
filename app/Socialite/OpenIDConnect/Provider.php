<?php

namespace App\Socialite\OpenIDConnect;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

class Provider extends AbstractProvider
{
    public const IDENTIFIER = 'OPENID';

    protected $scopeSeparator = ' ';

    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * The cached OpenID configuration.
     */
    protected ?array $openidConfiguration = null;

    public static function additionalConfigKeys(): array
    {
        return ['base_url'];
    }

    /**
     * Get the OpenID Connect discovery document.
     */
    protected function getOpenidConfiguration(): array
    {
        if ($this->openidConfiguration !== null) {
            return $this->openidConfiguration;
        }

        $baseUrl = rtrim($this->getConfig('base_url'), '/');
        $cacheKey = 'oauth.openid.configuration.'.md5($baseUrl);

        $this->openidConfiguration = Cache::remember($cacheKey, 3600, function () use ($baseUrl) {
            $response = $this->getHttpClient()->get($baseUrl.'/.well-known/openid-configuration');

            return json_decode((string) $response->getBody(), true);
        });

        return $this->openidConfiguration;
    }

    protected function getAuthUrl($state): string
    {
        $config = $this->getOpenidConfiguration();

        return $this->buildAuthUrlFromBase($config['authorization_endpoint'], $state);
    }

    protected function getTokenUrl(): string
    {
        $config = $this->getOpenidConfiguration();

        return $config['token_endpoint'];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUserByToken($token)
    {
        $config = $this->getOpenidConfiguration();

        $response = $this->getHttpClient()->get($config['userinfo_endpoint'], [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * {@inheritDoc}
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'preferred_username'),
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
        ]);
    }
}
