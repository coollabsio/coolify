<?php

namespace App\Auth\Oidc;

use App\Auth\Oidc\Exceptions\OidcTokenException;

class OidcTokenValidator
{
    /**
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed>
     */
    public function validate(
        string $idToken,
        OidcDiscoveryDocument $discovery,
        array $jwks,
        string $clientId,
        ?string $expectedNonce = null,
        int $clockSkewSeconds = 60,
    ): array {
        [$header, $claims, $signatureInput, $signature] = $this->parse($idToken);

        $algorithm = $header['alg'] ?? null;
        if ($algorithm !== 'RS256') {
            throw new OidcTokenException('id_token uses a disallowed algorithm.');
        }

        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new OidcTokenException('id_token header is missing kid.');
        }

        $jwk = $this->findJwk($jwks, $kid);
        $publicKey = RsaJwk::toPem($jwk);

        if (openssl_verify($signatureInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new OidcTokenException('id_token signature is invalid.');
        }

        $this->assertIssuer($claims, $discovery->issuer);
        $this->assertAudience($claims, $clientId);
        $this->assertTimestamps($claims, $clockSkewSeconds);
        $this->assertNonce($claims, $expectedNonce);

        return $claims;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function parse(string $idToken): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new OidcTokenException('Malformed id_token.');
        }

        $header = json_decode(RsaJwk::base64UrlDecode($segments[0]), true);
        $claims = json_decode(RsaJwk::base64UrlDecode($segments[1]), true);

        if (! is_array($header) || ! is_array($claims)) {
            throw new OidcTokenException('id_token contains invalid JSON.');
        }

        return [$header, $claims, $segments[0].'.'.$segments[1], RsaJwk::base64UrlDecode($segments[2])];
    }

    /**
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed>
     */
    private function findJwk(array $jwks, string $kid): array
    {
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                return $jwk;
            }
        }

        throw new OidcTokenException('No matching JWKS key found for id_token kid.');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertIssuer(array $claims, string $expectedIssuer): void
    {
        if (($claims['iss'] ?? null) !== $expectedIssuer) {
            throw new OidcTokenException('id_token issuer does not match discovery issuer.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertAudience(array $claims, string $clientId): void
    {
        $audience = $claims['aud'] ?? null;
        if (is_string($audience)) {
            $audience = [$audience];
        }

        if (! is_array($audience) || ! in_array($clientId, $audience, true)) {
            throw new OidcTokenException('id_token audience does not include configured client id.');
        }

        if (isset($claims['azp']) && $claims['azp'] !== $clientId) {
            throw new OidcTokenException('id_token azp does not match configured client id.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertTimestamps(array $claims, int $clockSkewSeconds): void
    {
        $now = time();
        $expiration = $claims['exp'] ?? null;
        if (! is_int($expiration) || ($expiration + $clockSkewSeconds) < $now) {
            throw new OidcTokenException('id_token is expired.');
        }

        $issuedAt = $claims['iat'] ?? null;
        if (! is_int($issuedAt) || ($issuedAt - $clockSkewSeconds) > $now) {
            throw new OidcTokenException('id_token issued at time is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertNonce(array $claims, ?string $expectedNonce): void
    {
        if ($expectedNonce === null) {
            return;
        }

        if (($claims['nonce'] ?? null) !== $expectedNonce) {
            throw new OidcTokenException('id_token nonce does not match.');
        }
    }
}
