<?php

use App\Livewire\Source\Gitea\Change;
use App\Models\GiteaApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeGiteaChangeViewApp(Team $team, array $attributes = []): GiteaApp
{
    return GiteaApp::create(array_merge([
        'name' => 'My Gitea',
        'api_url' => 'https://gitea.com/api/v1',
        'html_url' => 'https://gitea.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ], $attributes));
}

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

    $this->giteaApp = makeGiteaChangeViewApp($this->team);
});

describe('Gitea source setup view', function () {
    test('shows incomplete-setup guidance with the credential form', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->assertSee('Finish connecting this Gitea App before using it as a source')
            ->assertSee('https://gitea.com/user/settings/applications')
            ->assertSee('Client ID')
            ->assertSee('Client secret')
            ->assertSee('Save')
            ->assertDontSee('Test connection');
    });

    test('derives api url when gitea url changes', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->set('htmlUrl', 'https://gitea.example.com')
            ->assertSet('apiUrl', 'https://gitea.example.com/api/v1');
    });

    test('saves and reloads credentials and organization', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->set('clientId', 'gitea-client-id')
            ->set('clientSecretInput', 'super-secret-value')
            ->set('organization', 'myorg')
            ->call('submit')
            ->assertDispatched('success');

        $this->giteaApp->refresh();
        expect($this->giteaApp->client_secret)->toBe('super-secret-value')
            ->and($this->giteaApp->organization)->toBe('myorg');

        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->assertSet('clientId', 'gitea-client-id')
            ->assertSet('clientSecretInput', 'super-secret-value')
            ->assertSet('organization', 'myorg');
    });

    test('uses the gitea redirect path for a custom public endpoint', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->set('use_custom_webhook_endpoint', true)
            ->set('custom_webhook_endpoint', 'http://100.75.155.70:8000')
            ->assertSet('redirectUri', 'http://100.75.155.70:8000/webhooks/source/gitea/redirect');

        expect($this->giteaApp->refresh()->redirect_uri)
            ->toBe('http://100.75.155.70:8000/webhooks/source/gitea/redirect');
    });

    test('builds the gitea oauth url and caches the state payload', function () {
        $this->giteaApp->update(['client_id' => 'gitea-client-id']);

        $component = Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class);

        $state = $component->get('oauthState');
        $url = $component->instance()->getOAuthUrl();

        expect($url)->toStartWith('https://gitea.com/login/oauth/authorize?');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect($query)->toMatchArray([
            'client_id' => 'gitea-client-id',
            'response_type' => 'code',
            'scope' => 'read:user read:repository read:organization',
            'state' => $state,
        ])->and($query['redirect_uri'])->toEndWith('/webhooks/source/gitea/redirect');

        expect(Change::oauthStateCacheKey($state))->toBe('gitea-app-oauth-state:'.hash('sha256', $state))
            ->and(Cache::get(Change::oauthStateCacheKey($state)))->toBe([
                'gitea_app_id' => $this->giteaApp->id,
                'team_id' => $this->team->id,
            ]);
    });

    test('shows the webhook url and secret for a connected app', function () {
        $this->giteaApp->update([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => time() + 3600,
            'webhook_secret' => 'gitea-webhook-secret',
        ]);

        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->assertSee('/webhooks/source/gitea/events')
            ->assertSet('webhookSecret', 'gitea-webhook-secret')
            ->assertSee('Test connection');
    });

    test('test connection reports the authenticated gitea login', function () {
        $this->giteaApp->update([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => time() + 3600,
        ]);

        Http::fake([
            'gitea.com/api/v1/user' => Http::response(['login' => 'gitea-user'], 200),
        ]);

        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->call('testConnection')
            ->assertDispatched('success', 'Connection successful! Authenticated as: gitea-user');
    });

    test('redirects after the gitea app is deleted', function () {
        Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class)
            ->call('delete')
            ->assertRedirect(route('source.all'));

        $this->assertModelMissing($this->giteaApp);
    });
});

describe('Gitea source authorization', function () {
    test('user of another team cannot open a team gitea source', function () {
        $otherTeam = Team::factory()->create();
        $foreign = makeGiteaChangeViewApp($otherTeam, ['client_secret' => 'foreign-secret']);

        Livewire::withQueryParams(['gitea_app_uuid' => $foreign->uuid])
            ->test(Change::class)
            ->assertStatus(404);
    });

    test('unrelated users cannot see system-wide source secrets', function () {
        $otherTeam = Team::factory()->create();
        $shared = makeGiteaChangeViewApp($otherTeam, [
            'is_system_wide' => true,
            'client_secret' => 'shared-client-secret',
            'webhook_secret' => 'shared-webhook-secret',
        ]);

        $component = Livewire::withQueryParams(['gitea_app_uuid' => $shared->uuid])
            ->test(Change::class)
            ->assertSet('clientSecretInput', null)
            ->assertSet('webhookSecret', null);

        expect($component->html())
            ->not->toContain('shared-client-secret')
            ->not->toContain('shared-webhook-secret');
    });

    test('member of the source team cannot update it after switching to a team they own', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $ownTeam = Team::factory()->create();
        $ownTeam->members()->attach($member->id, ['role' => 'owner']);

        $this->actingAs($member);
        session(['currentTeam' => $this->team]);

        $component = Livewire::withQueryParams(['gitea_app_uuid' => $this->giteaApp->uuid])
            ->test(Change::class);

        session(['currentTeam' => $ownTeam]);

        $component->set('name', 'hijacked')
            ->call('submit')
            ->assertDispatched('error');
        $component->call('delete');

        $this->assertModelExists($this->giteaApp);
        expect($this->giteaApp->refresh()->name)->toBe('My Gitea');
    });
});
