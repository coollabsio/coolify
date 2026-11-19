<?php

use App\Models\OauthSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads the GitLab user from API v4 with a bearer token', function (?string $baseUrl, string $expectedUrl) {
    OauthSetting::create([
        'provider' => 'gitlab',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/gitlab/callback',
        'base_url' => $baseUrl,
        'enabled' => true,
    ]);

    $requests = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 42,
            'username' => 'gitlab-user',
            'name' => 'GitLab User',
            'email' => 'user@example.com',
            'avatar_url' => null,
            'confirmed_at' => '2026-01-10T09:05:22Z',
        ])),
    ]));
    $handler->push(Middleware::history($requests));

    $gitlabUser = get_socialite_provider('gitlab')
        ->setHttpClient(new Client(['handler' => $handler]))
        ->userFromToken('gitlab-access-token');

    $request = $requests[0]['request'];
    expect((string) $request->getUri())->toBe($expectedUrl)
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer gitlab-access-token')
        ->and($gitlabUser->getId())->toBe(42)
        ->and($gitlabUser->getEmail())->toBe('user@example.com')
        ->and($gitlabUser->user['confirmed_at'])->toBe('2026-01-10T09:05:22Z');
})->with([
    'gitlab.com' => [null, 'https://gitlab.com/api/v4/user'],
    'self-hosted GitLab' => ['https://gitlab.example.com/', 'https://gitlab.example.com/api/v4/user'],
]);
