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
     * Split a hostname into its registrable domain and relative host.
     *
     * Uses a small multi-part public-suffix set for common cases (e.g. co.uk)
     * instead of shipping the full Mozilla Public Suffix List (~300KB).
     * Unknown multi-part TLDs fall back to last-two-labels; Cloudflare will
     * still match the user's zone when the guess is close enough.
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

        $suffixLabelCount = self::publicSuffixLabelCount($labels);
        $registrableLabelCount = $suffixLabelCount + 1;

        if (count($labels) < $registrableLabelCount) {
            throw new InvalidArgumentException('Hostname must include a registrable domain.');
        }

        $domain = implode('.', array_slice($labels, -$registrableLabelCount));
        $hostLabels = array_slice($labels, 0, -$registrableLabelCount);
        $host = implode('.', $hostLabels);

        return ['domain' => $domain, 'host' => $host];
    }

    /**
     * How many trailing labels form the public suffix (effective TLD).
     *
     * @param  list<string>  $labels
     */
    protected static function publicSuffixLabelCount(array $labels): int
    {
        $count = count($labels);

        // Longest multi-part match first (up to 3 labels: e.g. com.au, co.uk).
        for ($length = min(3, $count - 1); $length >= 2; $length--) {
            $candidate = implode('.', array_slice($labels, -$length));
            if (isset(self::MULTI_PART_PUBLIC_SUFFIXES[$candidate])) {
                return $length;
            }
        }

        return 1;
    }

    /**
     * Common multi-label public suffixes. Keys only; values unused.
     * Not exhaustive — covers frequent Domain Connect / Cloudflare zones.
     *
     * @var array<string, true>
     */
    private const MULTI_PART_PUBLIC_SUFFIXES = [
        // United Kingdom / related
        'ac.uk' => true,
        'co.uk' => true,
        'gov.uk' => true,
        'ltd.uk' => true,
        'me.uk' => true,
        'net.uk' => true,
        'org.uk' => true,
        'plc.uk' => true,
        'sch.uk' => true,
        // Australia
        'com.au' => true,
        'net.au' => true,
        'org.au' => true,
        'edu.au' => true,
        'gov.au' => true,
        'asn.au' => true,
        'id.au' => true,
        // New Zealand
        'co.nz' => true,
        'net.nz' => true,
        'org.nz' => true,
        'govt.nz' => true,
        'ac.nz' => true,
        // Japan
        'co.jp' => true,
        'or.jp' => true,
        'ne.jp' => true,
        'ac.jp' => true,
        'go.jp' => true,
        // Brazil
        'com.br' => true,
        'net.br' => true,
        'org.br' => true,
        'gov.br' => true,
        // India
        'co.in' => true,
        'net.in' => true,
        'org.in' => true,
        'gen.in' => true,
        'firm.in' => true,
        'ind.in' => true,
        // South Africa
        'co.za' => true,
        'org.za' => true,
        'web.za' => true,
        'net.za' => true,
        // Mexico / LatAm
        'com.mx' => true,
        'org.mx' => true,
        'gob.mx' => true,
        'com.ar' => true,
        'com.co' => true,
        'com.pe' => true,
        'com.cl' => true,
        // Asia / others
        'com.cn' => true,
        'net.cn' => true,
        'org.cn' => true,
        'com.hk' => true,
        'com.sg' => true,
        'com.tw' => true,
        'com.my' => true,
        'com.ph' => true,
        'com.tr' => true,
        'com.ua' => true,
        'com.pl' => true,
        'com.ru' => true,
        'co.kr' => true,
        'co.il' => true,
        'com.sa' => true,
        'com.eg' => true,
        'com.ng' => true,
        // EU-style
        'co.at' => true,
        'or.at' => true,
        'co.nl' => true,
        'com.de' => true,
        // Platforms sometimes used as zones
        'github.io' => true,
        'pages.dev' => true,
    ];

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
