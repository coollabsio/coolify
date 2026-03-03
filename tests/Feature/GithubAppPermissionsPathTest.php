<?php

use App\Models\GithubApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeGithubApp(array $attributes = []): GithubApp
{
    $team = Team::factory()->create();

    return GithubApp::create(array_merge([
        'uuid' => fake()->uuid(),
        'name' => 'My Cool App',
        'html_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',
        'organization' => null,
        'team_id' => $team->id,
    ], $attributes));
}

it('returns user-level permissions path when no organization is set', function () {
    $app = makeGithubApp(['name' => 'My Cool App', 'organization' => null]);

    expect(getPermissionsPath($app))
        ->toBe('https://github.com/settings/apps/my-cool-app/permissions');
});

it('returns organization-level permissions path when organization is set', function () {
    $app = makeGithubApp(['name' => 'My Cool App', 'organization' => 'coollabsio']);

    expect(getPermissionsPath($app))
        ->toBe('https://github.com/organizations/coollabsio/settings/apps/my-cool-app/permissions');
});
