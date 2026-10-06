<?php

use App\Auth\Oidc\Exceptions\OidcSigningKeyNotFoundException;
use App\Auth\Oidc\Exceptions\OidcTokenException;
use App\Auth\Oidc\OidcDiscoveryDocument;
use App\Auth\Oidc\OidcTokenValidator;
use Tests\TestCase;

uses(TestCase::class);

function oidc_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function oidc_keyset(string $kid = 'test-key'): array
{
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($privateKey, $privatePem);
    $details = openssl_pkey_get_details($privateKey);

    return [
        'private_pem' => $privatePem,
        'jwks' => [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => $kid,
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => oidc_base64url($details['rsa']['n']),
                'e' => oidc_base64url($details['rsa']['e']),
            ]],
        ],
    ];
}

function oidc_token(array $claims, string $privatePem, string $kid = 'test-key', string $algorithm = 'RS256'): string
{
    $header = oidc_base64url(json_encode(['alg' => $algorithm, 'typ' => 'JWT', 'kid' => $kid], JSON_THROW_ON_ERROR));
    $payload = oidc_base64url(json_encode($claims, JSON_THROW_ON_ERROR));
    $signatureInput = $header.'.'.$payload;
    openssl_sign($signatureInput, $signature, $privatePem, OPENSSL_ALGO_SHA256);

    return $signatureInput.'.'.oidc_base64url($signature);
}

function oidc_discovery(): OidcDiscoveryDocument
{
    return new OidcDiscoveryDocument(
        issuer: 'https://idp.example.com',
        authorizationEndpoint: 'https://idp.example.com/oauth2/authorize',
        tokenEndpoint: 'https://idp.example.com/oauth2/token',
        userinfoEndpoint: 'https://idp.example.com/oauth2/userinfo',
        jwksUri: 'https://idp.example.com/.well-known/jwks.json',
    );
}

it('validates a well formed RS256 id token', function () {
    $keyset = oidc_keyset();
    $now = time();
    $token = oidc_token([
        'iss' => 'https://idp.example.com',
        'aud' => 'client-id',
        'sub' => 'okta-user-1',
        'iat' => $now,
        'exp' => $now + 600,
        'nonce' => 'expected-nonce',
        'email' => 'User@Example.com',
    ], $keyset['private_pem']);

    $claims = app(OidcTokenValidator::class)->validate(
        idToken: $token,
        discovery: oidc_discovery(),
        jwks: $keyset['jwks'],
        clientId: 'client-id',
        expectedNonce: 'expected-nonce',
    );

    expect($claims['sub'])->toBe('okta-user-1')
        ->and($claims['email'])->toBe('User@Example.com');
});

it('rejects invalid token claims', function (array $claimOverrides, string $message) {
    $keyset = oidc_keyset();
    $now = time();
    $claims = array_merge([
        'iss' => 'https://idp.example.com',
        'aud' => 'client-id',
        'sub' => 'okta-user-1',
        'iat' => $now,
        'exp' => $now + 600,
        'nonce' => 'expected-nonce',
    ], $claimOverrides);

    $token = oidc_token($claims, $keyset['private_pem']);

    app(OidcTokenValidator::class)->validate(
        idToken: $token,
        discovery: oidc_discovery(),
        jwks: $keyset['jwks'],
        clientId: 'client-id',
        expectedNonce: 'expected-nonce',
    );
})->throws(OidcTokenException::class)->with([
    'issuer mismatch' => [['iss' => 'https://evil.example.com'], 'issuer'],
    'audience mismatch' => [['aud' => 'other-client'], 'audience'],
    'azp missing for multi audience' => [['aud' => ['client-id', 'other-client']], 'azp'],
    'azp mismatch' => [['aud' => ['client-id', 'other-client'], 'azp' => 'other-client'], 'azp'],
    'expired token' => [['exp' => time() - 3600], 'expired'],
    'future issued at' => [['iat' => time() + 3600], 'issued'],
    'nonce mismatch' => [['nonce' => 'wrong-nonce'], 'nonce'],
    'missing subject' => [['sub' => null], 'subject'],
    'empty subject' => [['sub' => ''], 'subject'],
    'non-string subject' => [['sub' => 123], 'subject'],
]);

it('rejects a bad signature and unknown key id', function (string $kid) {
    $keyset = oidc_keyset('test-key');
    $otherKeyset = oidc_keyset($kid);
    $now = time();
    $token = oidc_token([
        'iss' => 'https://idp.example.com',
        'aud' => 'client-id',
        'sub' => 'okta-user-1',
        'iat' => $now,
        'exp' => $now + 600,
        'nonce' => 'expected-nonce',
    ], $otherKeyset['private_pem'], $kid);

    app(OidcTokenValidator::class)->validate(
        idToken: $token,
        discovery: oidc_discovery(),
        jwks: $keyset['jwks'],
        clientId: 'client-id',
        expectedNonce: 'expected-nonce',
    );
})->throws(OidcTokenException::class)->with([
    'same kid with bad signature' => ['test-key'],
    'unknown kid' => ['other-key'],
]);

it('rejects disallowed algorithms', function () {
    $keyset = oidc_keyset();
    $now = time();
    $token = oidc_token([
        'iss' => 'https://idp.example.com',
        'aud' => 'client-id',
        'sub' => 'okta-user-1',
        'iat' => $now,
        'exp' => $now + 600,
    ], $keyset['private_pem'], algorithm: 'HS256');

    app(OidcTokenValidator::class)->validate($token, oidc_discovery(), $keyset['jwks'], 'client-id');
})->throws(OidcTokenException::class);

it('throws a dedicated exception when the signing key is unknown', function () {
    $keyset = oidc_keyset('current-key');
    $token = oidc_token([
        'iss' => 'https://idp.example.com',
        'aud' => 'client-id',
        'sub' => 'okta-user-1',
        'iat' => time(),
        'exp' => time() + 600,
    ], $keyset['private_pem'], 'rotated-key');

    app(OidcTokenValidator::class)->validate($token, oidc_discovery(), $keyset['jwks'], 'client-id');
})->throws(OidcSigningKeyNotFoundException::class);

it('rejects a jwks key not designated for signing', function () {
    $keyset = oidc_keyset();
    $keyset['jwks']['keys'][0]['use'] = 'enc';
    $now = time();
    $token = oidc_token([
        'iss' => 'https://idp.example.com',
        'aud' => 'client-id',
        'sub' => 'okta-user-1',
        'iat' => $now,
        'exp' => $now + 600,
    ], $keyset['private_pem']);

    // An encryption-only key is dropped from the keyset, so the kid no longer resolves.
    app(OidcTokenValidator::class)->validate($token, oidc_discovery(), $keyset['jwks'], 'client-id');
})->throws(OidcTokenException::class);
