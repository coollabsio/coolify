<?php

use App\Livewire\Source\Github\Create;
use App\Models\GithubApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Set current team
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('GitHub Source Create Component', function () {
    test('creates github app with default values', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'my-test-app')
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::where('name', 'my-test-app')->first();

        expect($githubApp)->not->toBeNull();
        expect($githubApp->name)->toBe('my-test-app');
        expect($githubApp->api_url)->toBe('https://api.github.com');
        expect($githubApp->html_url)->toBe('https://github.com');
        expect($githubApp->custom_user)->toBe('git');
        expect($githubApp->custom_port)->toBe(22);
        expect($githubApp->is_system_wide)->toBeFalse();
        expect($githubApp->team_id)->toBe($this->team->id);
    });

    test('creates github app with system wide enabled', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'system-wide-app')
            ->set('is_system_wide', true)
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::where('name', 'system-wide-app')->first();

        expect($githubApp)->not->toBeNull();
        expect($githubApp->is_system_wide)->toBeTrue();
    });

    test('creates github app with custom organization', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'org-app')
            ->set('organization', 'my-org')
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::where('name', 'org-app')->first();

        expect($githubApp)->not->toBeNull();
        expect($githubApp->organization)->toBe('my-org');
    });

    test('creates github app with custom git settings', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'enterprise-app')
            ->set('api_url', 'https://github.enterprise.com/api/v3')
            ->set('html_url', 'https://github.enterprise.com')
            ->set('custom_user', 'git-custom')
            ->set('custom_port', 2222)
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::where('name', 'enterprise-app')->first();

        expect($githubApp)->not->toBeNull();
        expect($githubApp->api_url)->toBe('https://github.enterprise.com/api/v3');
        expect($githubApp->html_url)->toBe('https://github.enterprise.com');
        expect($githubApp->custom_user)->toBe('git-custom');
        expect($githubApp->custom_port)->toBe(2222);
    });

    test('validates required fields', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', '')
            ->call('createGitHubApp')
            ->assertHasErrors(['name']);
    });

    test('redirects to github app show page after creation', function () {
        $component = Livewire::test(Create::class)
            ->set('name', 'redirect-test')
            ->call('createGitHubApp');

        $githubApp = GithubApp::where('name', 'redirect-test')->first();

        $component->assertRedirect(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));
    });
});
