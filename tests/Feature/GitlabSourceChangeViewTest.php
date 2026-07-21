<?php

use App\Livewire\Source\Gitlab\Change;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
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

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => null,
        'public_ipv4' => null,
        'public_ipv6' => null,
    ]);

    $this->gitlabApp = GitlabApp::create([
        'name' => 'Self-hosted GitLab',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
});

describe('GitLab source setup view', function () {
    test('shows red incomplete-setup alert and keeps advanced fields collapsed', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->assertSee('You must complete this step before you can use this source!')
            ->assertSeeHtml('alert-error')
            ->assertSee('Advanced / Self-hosted')
            ->assertSee('Application ID')
            ->assertSee('Application Secret')
            ->assertSee('Save Credentials')
            ->assertDontSee('alert-warning');
    });

    test('derives api url when gitlab url changes', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->set('htmlUrl', 'https://gitlab.example.com')
            ->assertSet('apiUrl', 'https://gitlab.example.com/api/v4');
    });
});
