<?php

namespace App\Support\DomainConnect;

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds Cloudflare Domain Connect synchronous "apply template" URLs.
 *
 * @see https://developers.cloudflare.com/dns/reference/domain-connect/
 * @see https://github.com/Domain-Connect/spec/blob/master/Domain%20Connect%20Spec%20Draft.adoc
 */
class CloudflareDomainConnect
{
    public const CLOUDFLARE_SYNC_UX = 'https://dash.cloudflare.com/domainconnect';

    public function isConfigured(): bool
    {
        return filled($this->privateKeyPem());
    }

    /**
     * Domain Connect is a Coolify Cloud feature and requires a signing key
     * (instance setting or env) to be present at runtime.
     */
    public function isAvailable(): bool
    {
        return isCloud() && $this->isConfigured();
    }

    public function providerId(): string
    {
        return (string) config('services.domain_connect.provider_id', 'coolify.io');
    }

    public function serviceId(): string
    {
        return (string) config('services.domain_connect.service_id', 'hosting');
    }

    public function keyId(): string
    {
        return (string) config('services.domain_connect.key_id', '_dcpubkeyv1');
    }

    /**
     * @param  array<string, string|null>  $variables  Template variables (e.g. ip). Null values become empty strings.
     */
    public function buildApplyUrl(
        string $domain,
        array $variables = [],
        ?string $host = null,
        ?string $redirectUri = null,
    ): string {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Domain Connect is only available on Coolify Cloud when a Domain Connect private key is configured.'
            );
        }

        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim(explode('/', $domain)[0] ?? $domain, '.');

        if ($domain === '' || ! str_contains($domain, '.')) {
            throw new InvalidArgumentException('A valid domain is required for Cloudflare Domain Connect.');
        }

        $host = $host === null ? '' : strtolower(trim($host));
        $host = rtrim($host, '.');
        if ($host === '@') {
            $host = '';
        }

        // Signature covers the query string excluding `key` and `sig` (Domain Connect spec).
        $params = [
            'domain' => $domain,
            'host' => $host,
        ];

        foreach ($variables as $name => $value) {
            if ($name === 'domain' || $name === 'host' || $name === 'key' || $name === 'sig') {
                continue;
            }
            $params[$name] = $value === null ? '' : (string) $value;
        }

        if (filled($redirectUri)) {
            $params['redirect_uri'] = $redirectUri;
        }

        $queryToSign = $this->buildQueryString($params);
        $signature = $this->sign($queryToSign);

        // Cloudflare requires `sig` as the last query parameter; `key` is also required.
        $finalQuery = $queryToSign
            .'&key='.rawurlencode($this->keyId())
            .'&sig='.rawurlencode($signature);

        return self::CLOUDFLARE_SYNC_UX
            .'/v2/domainTemplates/providers/'.rawurlencode($this->providerId())
            .'/services/'.rawurlencode($this->serviceId())
            .'/apply?'.$finalQuery;
    }

    /**
     * Point a zone (and optional host) A record at the Coolify server IP.
     */
    public function buildHostingApplyUrl(
        string $domain,
        string $ip,
        ?string $host = null,
        ?string $redirectUri = null,
    ): string {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('A valid IPv4 or IPv6 address is required for DNS autoconfigure.');
        }

        return $this->buildApplyUrl(
            domain: $domain,
            variables: ['ip' => $ip],
            host: $host,
            redirectUri: $redirectUri,
        );
    }

    /**
     * Split a hostname like app.example.com into domain=example.com and host=app.
     * For apex (example.com) host is empty.
     *
     * @return array{domain: string, host: string}
     */
    public static function splitHostname(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname)[0] ?? $hostname;
        $hostname = explode(':', $hostname)[0] ?? $hostname;
        $hostname = rtrim($hostname, '.');

        $labels = array_values(array_filter(explode('.', $hostname), fn (string $p) => $p !== ''));

        if (count($labels) < 2) {
            throw new InvalidArgumentException('Hostname must include a domain (e.g. example.com or app.example.com).');
        }

        if (count($labels) === 2) {
            return ['domain' => implode('.', $labels), 'host' => ''];
        }

        // Multi-label: treat last two labels as the zone (example.com / co.uk-style multi-part TLDs
        // are imperfect without a public-suffix list; Cloudflare will match the user's zone).
        $domain = implode('.', array_slice($labels, -2));
        $host = implode('.', array_slice($labels, 0, -2));

        return ['domain' => $domain, 'host' => $host];
    }

    /**
     * @param  array<string, string>  $params
     */
    protected function buildQueryString(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            // Always include empty values (Resend sends host= for apex).
            $parts[] = rawurlencode((string) $key).'='.rawurlencode($value);
        }

        return implode('&', $parts);
    }

    protected function sign(string $queryString): string
    {
        $privateKey = openssl_pkey_get_private($this->privateKeyPem());
        if ($privateKey === false) {
            throw new RuntimeException('Invalid Domain Connect private key.');
        }

        $signature = '';
        $ok = openssl_sign($queryString, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('Failed to sign Domain Connect apply URL.');
        }

        return base64_encode($signature);
    }

    protected function privateKeyPem(): ?string
    {
        $key = null;

        try {
            $settingsKey = data_get(instanceSettings(), 'domain_connect_private_key');
            if (is_string($settingsKey) && trim($settingsKey) !== '') {
                $key = $settingsKey;
            }
        } catch (\Throwable) {
            // Instance settings may be unavailable during early boot/tests.
        }

        if ($key === null) {
            $envKey = config('services.domain_connect.private_key');
            if (is_string($envKey) && trim($envKey) !== '') {
                $key = $envKey;
            }
        }

        if ($key === null) {
            return null;
        }

        $key = str_replace(["\r\n", "\r"], "\n", $key);
        // Allow single-line env values with literal \n
        if (! str_contains($key, "\n") && str_contains($key, '\\n')) {
            $key = str_replace('\\n', "\n", $key);
        }

        return $key;
    }
}
