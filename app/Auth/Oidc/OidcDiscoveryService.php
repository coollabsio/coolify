<?php

namespace App\Auth\Oidc;

use App\Auth\Oidc\Exceptions\OidcDiscoveryException;
use App\Auth\Oidc\Exceptions\OidcJwksException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class OidcDiscoveryService
{
    public function discover(string $issuerUrl): OidcDiscoveryDocument
    {
        $this->assertHttpsUrl($issuerUrl, new OidcDiscoveryException('Issuer URL must be an absolute HTTPS URL.'));

        $issuerUrl = rtrim($issuerUrl, '/');
        $cacheKey = 'oidc:discovery:'.hash('sha256', $issuerUrl);

        $payload = Cache::remember($cacheKey, 3600, function () use ($issuerUrl): array {
            $url = $issuerUrl.'/.well-known/openid-configuration';

            try {
                $response = Http::timeout(5)->connectTimeout(3)->acceptJson()->get($url);
            } catch (Throwable $e) {
                throw new OidcDiscoveryException("Failed to fetch discovery document: {$e->getMessage()}", previous: $e);
            }

            if ($response->failed()) {
                throw new OidcDiscoveryException("Discovery endpoint returned HTTP {$response->status()}");
            }

            $json = $response->json();
            if (! is_array($json) || $json === []) {
                throw new OidcDiscoveryException('Discovery endpoint returned invalid JSON.');
            }

            return $json;
        });

        $discovery = OidcDiscoveryDocument::fromArray($payload);
        if (rtrim($discovery->issuer, '/') !== $issuerUrl) {
            throw new OidcDiscoveryException('Discovery issuer does not match the configured issuer URL.');
        }

        return $discovery;
    }

    /**
     * @return array<string, mixed>
     */
    public function jwks(string $jwksUri): array
    {
        $this->assertHttpsUrl($jwksUri, new OidcJwksException('JWKS URI must be an absolute HTTPS URL.'));

        $cacheKey = 'oidc:jwks:'.hash('sha256', $jwksUri);

        return Cache::remember($cacheKey, 21600, function () use ($jwksUri): array {
            try {
                $response = Http::timeout(5)->connectTimeout(3)->acceptJson()->get($jwksUri);
            } catch (Throwable $e) {
                throw new OidcJwksException("Failed to fetch JWKS: {$e->getMessage()}", previous: $e);
            }

            if ($response->failed()) {
                throw new OidcJwksException("JWKS endpoint returned HTTP {$response->status()}");
            }

            $json = $response->json();
            if (! is_array($json) || ! is_array($json['keys'] ?? null)) {
                throw new OidcJwksException("JWKS endpoint returned an invalid payload without 'keys'.");
            }

            return $json;
        });
    }

    private function assertHttpsUrl(string $url, Throwable $exception): void
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw $exception;
        }
    }
}
