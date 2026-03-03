<?php

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::create(['id' => 0]);
});

test('github source has dedicated routes for each tab page', function () {
    $githubApp = GithubApp::create([
        'name' => 'test-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);

    $this->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]))
        ->assertSuccessful();
    $this->get(route('source.github.permissions-events', ['github_app_uuid' => $githubApp->uuid]))
        ->assertSuccessful();
    $this->get(route('source.github.resources', ['github_app_uuid' => $githubApp->uuid]))
        ->assertSuccessful();
});

test('permissions and resources routes redirect to general if github app is not initialized yet', function () {
    $githubApp = GithubApp::create([
        'name' => 'test-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);

    $this->get(route('source.github.permissions-events', ['github_app_uuid' => $githubApp->uuid]))
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));
    $this->get(route('source.github.resources', ['github_app_uuid' => $githubApp->uuid]))
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));
});
