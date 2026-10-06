<?php

namespace App\Services\Auth;

use App\Models\OauthSetting;

/**
 * Computes the instance key stored in oauth_identities.issuer for non-OIDC
 * providers. Subject ids are only unique within one provider instance, so an
 * identity must be bound to the instance the provider is configured for:
 * the normalized base URL for self-hostable providers, the tenant for Azure,
 * and the provider name for providers that only exist as one SaaS instance.
 * OIDC identities use the verified iss claim instead.
 */
final class OauthIdentityIssuer
{
    /**
     * Self-hostable providers and the base URL used when none is configured.
     *
     * @var array<string, string|null>
     */
    public const array SELF_HOSTABLE_PROVIDERS = [
        'authentik' => null,
        'clerk' => null,
        'gitlab' => 'https://gitlab.com',
        'zitadel' => null,
    ];

    /**
     * Azure endpoints that accept users from any tenant. Object ids are
     * globally unique GUIDs there, so they share one stable key.
     *
     * @var array<int, string>
     */
    public const array AZURE_MULTI_TENANT_ENDPOINTS = ['common', 'organizations', 'consumers'];

    public static function forSetting(OauthSetting $oauthSetting): ?string
    {
        return self::forProvider($oauthSetting->provider, $oauthSetting->base_url, $oauthSetting->tenant);
    }

    /**
     * Returns null for OIDC and for self-hostable providers without a usable
     * base URL.
     */
    public static function forProvider(string $provider, ?string $baseUrl, ?string $tenant): ?string
    {
        if ($provider === 'oidc') {
            return null;
        }

        if (array_key_exists($provider, self::SELF_HOSTABLE_PROVIDERS)) {
            $baseUrl = trim((string) $baseUrl);

            return self::normalizeBaseUrl($baseUrl !== '' ? $baseUrl : self::SELF_HOSTABLE_PROVIDERS[$provider]);
        }

        if ($provider === 'azure') {
            $tenant = strtolower(trim((string) $tenant));

            return $tenant === '' || in_array($tenant, self::AZURE_MULTI_TENANT_ENDPOINTS, true) ? 'azure' : $tenant;
        }

        return $provider;
    }

    /**
     * Lowercases scheme and host, drops default ports, credentials, query and
     * fragment, and keeps the path without a trailing slash.
     */
    public static function normalizeBaseUrl(?string $baseUrl): ?string
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return null;
        }

        if (! str_contains($baseUrl, '://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || blank($parts['host'] ?? null) || blank($parts['scheme'] ?? null)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '').$path;
    }
}
