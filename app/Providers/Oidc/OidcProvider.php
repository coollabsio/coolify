<?php

namespace App\Providers\Oidc;

use Illuminate\Support\Arr;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

class OidcProvider extends AbstractProvider
{
    /**
     * Unique Provider Identifier.
     */
    public const IDENTIFIER = 'OIDC';

    /**
     * The scopes being requested.
     *
     * @var array
     */
    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * Get the OpenID Connect configuration.
     *
     * @return array
     */
    protected function getOpenIdConfiguration()
    {
        return cache()->remember('oidc_configuration_' . $this->getConfig('base_url'), 3600, function () {
            $baseUrl = rtrim($this->getConfig('base_url'), '/');
            $discoveryUrl = $baseUrl . '/.well-known/openid-configuration';

            $response = $this->getHttpClient()->get($discoveryUrl);

            return json_decode($response->getBody(), true);
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        $url = $this->getOpenIdConfiguration()['authorization_endpoint'];

        return $this->buildAuthUrlFromBase($url, $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return $this->getOpenIdConfiguration()['token_endpoint'];
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $url = $this->getOpenIdConfiguration()['userinfo_endpoint'];

        $response = $this->getHttpClient()->get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User())->setRaw($user)->map([
            'id'       => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'preferred_username', Arr::get($user, 'nickname')),
            'name'     => Arr::get($user, 'name', Arr::get($user, 'given_name', '') . ' ' . Arr::get($user, 'family_name', '')),
            'email'    => Arr::get($user, 'email'),
            'avatar'   => Arr::get($user, 'picture'),
        ]);
    }
}
