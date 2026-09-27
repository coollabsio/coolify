<?php

namespace App\Auth\Oidc;

use App\Auth\Oidc\Exceptions\OidcSigningKeyNotFoundException;
use App\Auth\Oidc\Exceptions\OidcTokenException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

class OidcTokenValidator
{
    /**
     * Algorithms we accept for id_token signatures. RS256 only — this is the
     * OIDC baseline and a strict allowlist prevents algorithm-confusion and
     * "none" attacks.
     */
    private const ALLOWED_ALGORITHM = 'RS256';

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
        $kid = $this->extractKid($idToken);

        try {
            $keys = JWK::parseKeySet($this->signingKeysOnly($jwks), self::ALLOWED_ALGORITHM);
        } catch (Throwable $e) {
            throw new OidcTokenException("Unable to parse JWKS: {$e->getMessage()}", previous: $e);
        }

        // Surface an unknown signing key distinctly so the caller can refresh
        // the JWKS once (key rotation) before giving up.
        if (! array_key_exists($kid, $keys)) {
            throw new OidcSigningKeyNotFoundException('No matching JWKS key found for id_token kid.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $clockSkewSeconds;

        try {
            // Validates signature, header alg against the key alg (RS256),
            // exp, nbf and iat. Throws on any failure.
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (OidcTokenException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OidcTokenException("id_token validation failed: {$e->getMessage()}", previous: $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $this->assertExpiry($claims);
        $this->assertIssuer($claims, $discovery->issuer);
        $this->assertAudience($claims, $clientId);
        $this->assertNonce($claims, $expectedNonce);
        $this->assertSubject($claims);

        return $claims;
    }

    /**
     * Drop JWKS entries explicitly marked for anything other than signing
     * (e.g. "use":"enc") so they can never verify an id_token signature.
     * firebase/php-jwt does not honour the "use" parameter on its own.
     *
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed>
     */
    private function signingKeysOnly(array $jwks): array
    {
        $keys = array_values(array_filter(
            $jwks['keys'] ?? [],
            fn ($jwk): bool => is_array($jwk) && (! isset($jwk['use']) || $jwk['use'] === 'sig'),
        ));

        return ['keys' => $keys];
    }

    /**
     * Decode just the JWT header to read the kid before signature
     * verification, so an unknown key can be reported as a rotation miss.
     */
    private function extractKid(string $idToken): string
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new OidcTokenException('Malformed id_token.');
        }

        $header = json_decode($this->base64UrlDecode($segments[0]), true);
        if (! is_array($header)) {
            throw new OidcTokenException('id_token header contains invalid JSON.');
        }

        if (($header['alg'] ?? null) !== self::ALLOWED_ALGORITHM) {
            throw new OidcTokenException('id_token uses a disallowed algorithm.');
        }

        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new OidcTokenException('id_token header is missing kid.');
        }

        return $kid;
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new OidcTokenException('Invalid base64url value in id_token header.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertExpiry(array $claims): void
    {
        // Firebase enforces the exp window when present; OIDC requires it to exist.
        if (! is_numeric($claims['exp'] ?? null)) {
            throw new OidcTokenException('id_token is missing the exp claim.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertSubject(array $claims): void
    {
        $subject = $claims['sub'] ?? null;
        if (! is_string($subject) || $subject === '') {
            throw new OidcTokenException('id_token subject is missing or invalid.');
        }
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

        if (count($audience) > 1 && (! isset($claims['azp']) || $claims['azp'] !== $clientId)) {
            throw new OidcTokenException('id_token azp is required when aud contains multiple values and must match configured client id.');
        }

        if (isset($claims['azp']) && $claims['azp'] !== $clientId) {
            throw new OidcTokenException('id_token azp does not match configured client id.');
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
