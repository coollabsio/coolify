<?php

namespace App\Actions\Sentinel;

use RuntimeException;

class ResolveFluxPublicUrl
{
    public function resolve(): string
    {
        $configuredUrl = config('constants.flux.public_url');
        if (is_string($configuredUrl) && $configuredUrl !== '') {
            return rtrim($configuredUrl, '/');
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

        return "{$scheme}://{$host}:{$port}";
    }
}
