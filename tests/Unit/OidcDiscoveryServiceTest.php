<?php

use App\Auth\Oidc\Exceptions\OidcDiscoveryException;
use App\Auth\Oidc\Exceptions\OidcJwksException;
use App\Auth\Oidc\OidcDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('fetches and caches discovery documents and jwks', function () {
    Cache::flush();
    Http::fake([
        'https://idp.example.com/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://idp.example.com',
            'authorization_endpoint' => 'https://idp.example.com/auth',
            'token_endpoint' => 'https://idp.example.com/token',
            'userinfo_endpoint' => 'https://idp.example.com/userinfo',
            'jwks_uri' => 'https://idp.example.com/jwks',
        ]),
        'https://idp.example.com/jwks' => Http::response(['keys' => [['kid' => 'one']]]),
    ]);

    $service = app(OidcDiscoveryService::class);

    $discovery = $service->discover('https://idp.example.com');
    $jwks = $service->jwks($discovery->jwksUri);

    expect($discovery->issuer)->toBe('https://idp.example.com')
        ->and($jwks['keys'][0]['kid'])->toBe('one');

    Http::assertSentCount(2);

    $service->discover('https://idp.example.com');
    $service->jwks('https://idp.example.com/jwks');

    Http::assertSentCount(2);
});

it('rejects invalid discovery and jwks payloads', function () {
    Cache::flush();
    Http::fake([
        'https://bad.example.com/.well-known/openid-configuration' => Http::response(['issuer' => 'https://bad.example.com']),
    ]);

    app(OidcDiscoveryService::class)->discover('https://bad.example.com');
})->throws(OidcDiscoveryException::class);

it('rejects jwks responses without keys', function () {
    Cache::flush();
    Http::fake([
        'https://idp.example.com/jwks' => Http::response(['empty' => true]),
    ]);

    app(OidcDiscoveryService::class)->jwks('https://idp.example.com/jwks');
})->throws(OidcJwksException::class);
