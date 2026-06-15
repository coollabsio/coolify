<?php

use App\Auth\Oidc\OidcConfig;
use App\Auth\Oidc\OidcDiscoveryDocument;
use App\Auth\Oidc\OidcDiscoveryService;
use App\Auth\Oidc\OidcTokenValidator;
use App\Auth\Oidc\Socialite\OidcProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class);

class TestOidcProviderWithExposedAuthUrl extends OidcProvider
{
    public function authUrlForState(string $state): string
    {
        return $this->getAuthUrl($state);
    }
}

function oidc_provider_discovery_document(): OidcDiscoveryDocument
{
    return new OidcDiscoveryDocument(
        issuer: 'https://idp.example.com',
        authorizationEndpoint: 'https://idp.example.com/oauth2/authorize',
        tokenEndpoint: 'https://idp.example.com/oauth2/token',
        userinfoEndpoint: 'https://idp.example.com/oauth2/userinfo',
        jwksUri: 'https://idp.example.com/.well-known/jwks.json',
    );
}

function oidc_provider_session(): Store
{
    $session = new Store('testing', new ArraySessionHandler(1200));
    $session->start();

    return $session;
}

function oidc_provider_request(Store $session, string $state = 'state-value'): Request
{
    $request = Request::create('/auth/oidc/callback', 'GET', ['state' => $state]);
    $request->setLaravelSession($session);

    return $request;
}

function oidc_provider(Request $request): TestOidcProviderWithExposedAuthUrl
{
    /** @var OidcDiscoveryService&MockInterface $discoveryService */
    $discoveryService = Mockery::mock(OidcDiscoveryService::class);
    $discoveryService->shouldReceive('discover')
        ->byDefault()
        ->with('https://idp.example.com')
        ->andReturn(oidc_provider_discovery_document());

    /** @var OidcTokenValidator&MockInterface $tokenValidator */
    $tokenValidator = Mockery::mock(OidcTokenValidator::class);

    return (new TestOidcProviderWithExposedAuthUrl(
        $request,
        $discoveryService,
        $tokenValidator,
        'client-id',
        'client-secret',
        'https://coolify.example.com/auth/oidc/callback',
    ))->setConfig(new OidcConfig(
        issuerUrl: 'https://idp.example.com',
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://coolify.example.com/auth/oidc/callback',
        usePkce: true,
    ));
}

it('stores oidc nonce and pkce verifier with a ten minute expiry', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    try {
        $session = oidc_provider_session();
        $provider = oidc_provider(oidc_provider_request($session));

        $provider->authUrlForState('state-value');

        $nonceEntry = $session->get('oidc.nonce.state-value');
        $verifierEntry = $session->get('oidc.code_verifier.state-value');

        expect($nonceEntry)->toBeArray()
            ->and($nonceEntry['value'])->toBeString()->not->toBeEmpty()
            ->and($nonceEntry['expires_at'])->toBe(now()->addMinutes(10)->timestamp)
            ->and($verifierEntry)->toBeArray()
            ->and($verifierEntry['value'])->toBeString()->not->toBeEmpty()
            ->and($verifierEntry['expires_at'])->toBe(now()->addMinutes(10)->timestamp);
    } finally {
        Carbon::setTestNow();
    }
});

it('sends a fresh oidc pkce verifier during token exchange', function () {
    $session = oidc_provider_session();
    $session->put('oidc.code_verifier.state-value', [
        'value' => 'fresh-verifier',
        'expires_at' => now()->addMinute()->timestamp,
    ]);

    $provider = oidc_provider(oidc_provider_request($session));
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['access_token' => 'access-token', 'id_token' => 'id-token'], JSON_THROW_ON_ERROR)),
    ]));
    $handler->push(Middleware::history($history));
    $provider->setHttpClient(new Client(['handler' => $handler]));

    $provider->getAccessTokenResponse('authorization-code');

    parse_str((string) $history[0]['request']->getBody(), $tokenRequestFields);

    expect($tokenRequestFields['code_verifier'] ?? null)->toBe('fresh-verifier')
        ->and($session->has('oidc.code_verifier.state-value'))->toBeFalse();
});

it('does not send an expired oidc pkce verifier during token exchange', function () {
    $session = oidc_provider_session();
    $session->put('oidc.code_verifier.state-value', [
        'value' => 'expired-verifier',
        'expires_at' => now()->subSecond()->timestamp,
    ]);

    $provider = oidc_provider(oidc_provider_request($session));
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['access_token' => 'access-token', 'id_token' => 'id-token'], JSON_THROW_ON_ERROR)),
    ]));
    $handler->push(Middleware::history($history));
    $provider->setHttpClient(new Client(['handler' => $handler]));

    $provider->getAccessTokenResponse('authorization-code');

    parse_str((string) $history[0]['request']->getBody(), $tokenRequestFields);

    expect($tokenRequestFields)->not->toHaveKey('code_verifier')
        ->and($session->has('oidc.code_verifier.state-value'))->toBeFalse();
});
