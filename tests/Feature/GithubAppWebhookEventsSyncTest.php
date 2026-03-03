<?php

use App\Models\GithubApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function setupGithubAppWithKey(array $appAttributes = []): GithubApp
{
    $team = Team::factory()->create();
    $privateKeyId = DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => 'test-key-value',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return GithubApp::create(array_merge([
        'uuid' => fake()->uuid(),
        'name' => 'test-app',
        'html_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',
        'team_id' => $team->id,
        'app_id' => 12345,
        'private_key_id' => $privateKeyId,
    ], $appAttributes));
}

it('stores webhook events from github api response', function () {
    $app = setupGithubAppWithKey();

    Http::fake([
        '*/app' => Http::response([
            'permissions' => ['contents' => 'read', 'metadata' => 'read'],
            'events' => ['push', 'installation', 'pull_request'],
        ]),
    ]);

    $response = Http::get('https://api.github.com/app')->json();
    $app->webhook_events = data_get($response, 'events', []);
    $app->save();
    $app->refresh();

    expect($app->webhook_events)->toBe(['push', 'installation', 'pull_request']);
});

it('auto-fixes missing events by patching github api', function () {
    $app = setupGithubAppWithKey([
        'webhook_events' => ['push'],
        'pull_requests' => 'write',
    ]);

    Http::fake([
        'api.github.com/app' => Http::response([
            'events' => ['push', 'pull_request'],
        ]),
    ]);

    $missing = $app->missingWebhookEvents();
    expect($missing)->toContain('pull_request');

    $updatedEvents = array_values(array_unique(
        array_merge($app->webhook_events ?? [], $missing)
    ));

    $response = Http::withHeaders([
        'Authorization' => 'Bearer fake-jwt',
        'Accept' => 'application/vnd.github+json',
    ])->patch('https://api.github.com/app', [
        'events' => $updatedEvents,
    ]);

    expect($response->successful())->toBeTrue();

    $app->webhook_events = data_get($response->json(), 'events', $updatedEvents);
    $app->save();
    $app->refresh();

    expect($app->webhook_events)
        ->toContain('push')
        ->toContain('pull_request');

    Http::assertSent(function ($request) {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), '/app')
            && in_array('pull_request', $request['events']);
    });
});

it('does not patch when no events are missing', function () {
    $app = setupGithubAppWithKey([
        'webhook_events' => ['push'],
    ]);

    expect($app->missingWebhookEvents())->toBe([]);
});
