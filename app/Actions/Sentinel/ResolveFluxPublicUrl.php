<?php

namespace App\Actions\Sentinel;

use RuntimeException;

class ResolveFluxPublicUrl
{
    public function resolve(): string
    {
        $configuredUrl = config('constants.flux.public_url');
        if (is_string($configuredUrl) && $configuredUrl !== '') {
            $url = rtrim($configuredUrl, '/');

            return $this->validateScheme($url);
        }

        $appUrl = parse_url((string) config('app.url'));
        $scheme = $appUrl['scheme'] ?? null;
        $host = $appUrl['host'] ?? null;
        $port = (int) config('constants.flux.port', 7443);
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('Flux public URL configuration is invalid.');
        }

        if (str_contains($host, ':')) {
            $host = "[$host]";
        }

        return $this->validateScheme("{$scheme}://{$host}:{$port}");
    }

    private function validateScheme(string $url): string
    {
        if (str_starts_with($url, 'http://') && ! (isDev() && config('constants.flux.development_allow_plaintext', false))) {
            throw new RuntimeException('Flux requires TLS.');
        }

        return $url;
    }
}
