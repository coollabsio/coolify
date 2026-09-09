<?php

use App\Livewire\Source\Gitlab\Create;
use App\Models\GitlabApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('GitLab source create modal', function () {
    test('matches github create modal structure', function () {
        Livewire::test(Create::class)
            ->assertSee('This is required if you would like to get full integration')
            ->assertSee('Self-hosted GitLab')
            ->assertSee('Continue')
            ->assertDontSee('>Save</', false)
            ->assertDontSeeHtml('<h2>New GitLab App</h2>');
    });

    test('creates a gitlab app with defaults for gitlab.com', function () {
        Livewire::test(Create::class)
            ->set('name', 'my-gitlab')
            ->call('createGitLabApp')
            ->assertRedirect();

        $app = GitlabApp::where('name', 'my-gitlab')->first();
        expect($app)->not->toBeNull()
            ->and($app->html_url)->toBe('https://gitlab.com')
            ->and($app->api_url)->toBe('https://gitlab.com/api/v4')
            ->and($app->custom_user)->toBe('git')
            ->and($app->custom_port)->toBe(22);
    });

    test('derives api url when html url changes', function () {
        Livewire::test(Create::class)
            ->set('html_url', 'https://gitlab.example.com')
            ->assertSet('api_url', 'https://gitlab.example.com/api/v4');
    });
});
