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
            ->assertSee('Save')
            ->assertDontSee('alert-warning');
    });

    test('derives api url when gitlab url changes', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->set('htmlUrl', 'https://gitlab.example.com')
            ->assertSet('apiUrl', 'https://gitlab.example.com/api/v4');
    });

    test('saves and reloads the application secret after refresh', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->set('clientId', 'gitlab-app-id')
            ->set('clientSecretInput', 'super-secret-value')
            ->call('submit')
            ->assertDispatched('success');

        $this->gitlabApp->refresh()->makeVisible(['client_secret']);
        expect($this->gitlabApp->client_secret)->toBe('super-secret-value');

        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->assertSet('clientId', 'gitlab-app-id')
            ->assertSet('clientSecretInput', 'super-secret-value');
    });

    test('supports github-style custom public endpoint for oauth redirect uri', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->assertSee('Use custom webhook endpoint')
            ->assertSee('Selected endpoint')
            ->set('use_custom_webhook_endpoint', true)
            ->set('custom_webhook_endpoint', 'http://100.75.155.70:8000')
            ->assertSet('redirectUri', 'http://100.75.155.70:8000/webhooks/source/gitlab/redirect');

        expect($this->gitlabApp->refresh()->redirect_uri)
            ->toBe('http://100.75.155.70:8000/webhooks/source/gitlab/redirect');
    });
});
