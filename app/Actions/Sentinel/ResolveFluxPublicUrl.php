<?php

namespace App\Actions\Sentinel;

use RuntimeException;

class ResolveFluxPublicUrl
{
    /** Flux also serves these names, so Coolify can check the certificate inside its container. */
    private const LOCAL_IDENTITIES = ['localhost', '127.0.0.1', '::1'];

    /**
     * FLUX_PUBLIC_URL overrides the Flux URL setting (development, Coolify Cloud).
     */
    public function resolve(): string
    {
        $configuredUrl = config('constants.flux.public_url');
        if (is_string($configuredUrl) && $configuredUrl !== '') {
            $url = rtrim($configuredUrl, '/');

            return $this->validateScheme($url);
        }

        $settings = instanceSettings();
        $host = $settings->flux_host ?: self::defaultHost();
        $port = $settings->flux_port ?: (int) config('constants.flux.port', 7443);
        if ($host === null || $port < 1 || $port > 65535) {
            throw new RuntimeException('Flux URL configuration is invalid. Set the Flux URL in Settings > Advanced.');
        }

        if (str_contains($host, ':')) {
            $host = "[$host]";
        }

        return "https://{$host}:{$port}";
    }

    /**
     * The host of the instance domain, else the public IPv4 or IPv6 address (the order of base_url()).
     */
    public static function defaultHost(): ?string
    {
        $settings = instanceSettings();
        $host = $settings->fqdn ? parse_url($settings->fqdn, PHP_URL_HOST) : null;

        return ($host ?: null) ?? ($settings->public_ipv4 ?: null) ?? ($settings->public_ipv6 ?: null);
    }

    /**
     * The names of the Flux server certificate: the host of the Flux URL first, which Sentinel verifies.
     *
     * @return list<string>
     */
    public function certificateIdentities(): array
    {
        $host = trim((string) parse_url($this->resolve(), PHP_URL_HOST), '[]');

        return array_values(array_unique([$host, ...self::LOCAL_IDENTITIES]));
    }

    private function validateScheme(string $url): string
    {
        if (str_starts_with($url, 'http://') && ! (isDev() && config('constants.flux.development_allow_plaintext', false))) {
            throw new RuntimeException('Flux requires TLS.');
        }

        return $url;
    }
}
