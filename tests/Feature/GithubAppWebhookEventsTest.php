<?php

use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGithubAppForEvents(array $attributes = []): GithubApp
{
    $team = Team::factory()->create();

    return GithubApp::create(array_merge([
        'uuid' => fake()->uuid(),
        'name' => 'test-app',
        'html_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',
        'team_id' => $team->id,
    ], $attributes));
}

it('returns base required events when no special config exists', function () {
    $app = createGithubAppForEvents();

    expect($app->requiredWebhookEvents())
        ->toBe(['push']);
});

it('includes pull_request when pull_requests permission is write', function () {
    $app = createGithubAppForEvents(['pull_requests' => 'write']);

    expect($app->requiredWebhookEvents())
        ->toContain('pull_request');
});

it('does not include pull_request when pull_requests permission is read', function () {
    $app = createGithubAppForEvents(['pull_requests' => 'read']);

    expect($app->requiredWebhookEvents())
        ->not->toContain('pull_request');
});

it('includes workflow_job when runner configs exist', function () {
    $app = createGithubAppForEvents();
    $server = Server::factory()->create(['team_id' => $app->team_id]);

    GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $app->id,
        'labels' => ['self-hosted'],
    ]);

    expect($app->requiredWebhookEvents())
        ->toContain('workflow_job');
});

it('returns missing events correctly', function () {
    $app = createGithubAppForEvents([
        'webhook_events' => ['push'],
    ]);

    $missing = $app->missingWebhookEvents();

    expect($missing)->toBe([]);
});

it('returns no missing events when all required events are present', function () {
    $app = createGithubAppForEvents([
        'webhook_events' => ['push'],
    ]);

    expect($app->missingWebhookEvents())->toBe([]);
});

it('returns missing workflow_job when runner config exists but event is not subscribed', function () {
    $app = createGithubAppForEvents([
        'webhook_events' => ['push', 'installation'],
    ]);
    $server = Server::factory()->create(['team_id' => $app->team_id]);

    GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $app->id,
        'labels' => ['self-hosted'],
    ]);

    expect($app->missingWebhookEvents())->toContain('workflow_job');
});
