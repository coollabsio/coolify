<?php

use App\Livewire\Source\Gitea\Create;
use App\Models\GiteaApp;
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

describe('Gitea source create modal', function () {
    test('renders the create form', function () {
        Livewire::test(Create::class)
            ->assertSee('Self-hosted Gitea')
            ->assertSee('Organization')
            ->assertSee('Continue');
    });

    test('creates a gitea app with defaults for gitea.com and redirects to it', function () {
        $component = Livewire::test(Create::class)
            ->set('name', 'my-gitea')
            ->set('organization', 'myorg,myuser')
            ->call('createGiteaApp');

        $app = GiteaApp::where('name', 'my-gitea')->first();
        expect($app)->not->toBeNull()
            ->and($app->html_url)->toBe('https://gitea.com')
            ->and($app->api_url)->toBe('https://gitea.com/api/v1')
            ->and($app->custom_user)->toBe('git')
            ->and($app->custom_port)->toBe(22)
            ->and($app->organization)->toBe('myorg,myuser')
            ->and($app->team_id)->toBe($this->team->id)
            ->and(strlen((string) $app->webhook_secret))->toBe(32);

        $component->assertRedirect(route('source.gitea.show', ['gitea_app_uuid' => $app->uuid]));
    });

    test('derives api url when html url changes', function () {
        Livewire::test(Create::class)
            ->set('html_url', 'https://gitea.example.com')
            ->assertSet('api_url', 'https://gitea.example.com/api/v1');
    });

    test('team member cannot create a gitea app', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);

        Livewire::test(Create::class)
            ->set('name', 'member-gitea')
            ->call('createGiteaApp');

        expect(GiteaApp::where('name', 'member-gitea')->exists())->toBeFalse();
    });
});
