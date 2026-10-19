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
    test('shows incomplete-setup guidance with the credential form', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->assertSee('Finish connecting this GitLab App before using it as a source')
            ->assertSee('Advanced / self-hosted')
            ->assertSee('Application ID')
            ->assertSee('Application secret')
            ->assertSee('Save')
            ->assertDontSee('Test connection');
    });

    test('derives api url when gitlab url changes', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->set('htmlUrl', 'https://gitlab.example.com')
            ->assertSet('apiUrl', 'https://gitlab.example.com/api/v4');
    });

    test('redirects without rendering an error after the gitlab app is deleted', function () {
        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->call('delete')
            ->assertRedirect(route('source.all'));

        $this->assertModelMissing($this->gitlabApp);
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
            ->assertSee('Webhook endpoint')
            ->assertSee('Use a custom endpoint')
            ->assertSee('Selected endpoint')
            ->set('use_custom_webhook_endpoint', true)
            ->set('custom_webhook_endpoint', 'http://100.75.155.70:8000')
            ->assertSet('redirectUri', 'http://100.75.155.70:8000/webhooks/source/gitlab/redirect');

        expect($this->gitlabApp->refresh()->redirect_uri)
            ->toBe('http://100.75.155.70:8000/webhooks/source/gitlab/redirect');
    });
});

describe('GitLab webhook endpoint options for IPv6 instance addresses', function () {
    test('brackets the public IPv6 address and keeps IPv4 unchanged', function (string $ipv6) {
        config(['app.port' => 8000]);

        InstanceSettings::findOrFail(0)->update([
            'public_ipv4' => '203.0.113.10',
            'public_ipv6' => $ipv6,
        ]);

        Livewire::withQueryParams(['gitlab_app_uuid' => $this->gitlabApp->uuid])
            ->test(Change::class)
            ->assertSet('ipv4', 'http://203.0.113.10:8000')
            ->assertSet('ipv6', 'http://[2a01:4f8::1]:8000');
    })->with([
        'raw' => '2a01:4f8::1',
        'bracketed' => '[2a01:4f8::1]',
    ]);
});
