<?php

namespace App\Auth\Oidc;

use App\Auth\Oidc\Exceptions\OidcDiscoveryException;

final readonly class OidcDiscoveryDocument
{
    /**
     * @param  array<int, string>  $supportedScopes
     * @param  array<int, string>  $supportedClaims
     * @param  array<int, string>  $idTokenSigningAlgValuesSupported
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $userinfoEndpoint,
        public string $jwksUri,
        public ?string $endSessionEndpoint = null,
        public array $supportedScopes = [],
        public array $supportedClaims = [],
        public array $idTokenSigningAlgValuesSupported = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $field) {
            if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                throw new OidcDiscoveryException("Discovery document is missing required field: {$field}");
            }
        }

        return new self(
            issuer: $payload['issuer'],
            authorizationEndpoint: $payload['authorization_endpoint'],
            tokenEndpoint: $payload['token_endpoint'],
            userinfoEndpoint: $payload['userinfo_endpoint'],
            jwksUri: $payload['jwks_uri'],
            endSessionEndpoint: is_string($payload['end_session_endpoint'] ?? null) ? $payload['end_session_endpoint'] : null,
            supportedScopes: self::stringList($payload['scopes_supported'] ?? []),
            supportedClaims: self::stringList($payload['claims_supported'] ?? []),
            idTokenSigningAlgValuesSupported: self::stringList($payload['id_token_signing_alg_values_supported'] ?? []),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
