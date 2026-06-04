<?php

namespace App\Auth\Oidc;

use App\Models\OauthSetting;

final readonly class OidcConfig
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $issuerUrl,
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
        public array $scopes = ['openid', 'email', 'profile'],
        public bool $usePkce = true,
        public int $clockSkewSeconds = 60,
    ) {}

    public static function fromOauthSetting(OauthSetting $setting): self
    {
        return new self(
            issuerUrl: rtrim((string) $setting->base_url, '/'),
            clientId: (string) $setting->client_id,
            clientSecret: (string) $setting->client_secret,
            redirectUri: filled($setting->redirect_uri) ? $setting->redirect_uri : route('auth.callback', 'oidc'),
            scopes: $setting->scopeList(),
            usePkce: $setting->use_pkce ?? true,
            clockSkewSeconds: $setting->clock_skew_seconds ?: 60,
        );
    }
}
