<?php

use App\Livewire\Source\Gitea\Change as GiteaSource;
use App\Models\GiteaApp;
use App\Models\Team;
use App\Models\User;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::macro('GitSource', fn (string $url) => Http::withOptions(SafeExternalUrl::httpClientOptions(
        $url,
        resolver: fn (string $host): array => ['93.184.216.34'],
    )));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->giteaApp = GiteaApp::create([
        'name' => 'Self-hosted Gitea',
        'api_url' => 'https://gitea.example.com/api/v1',
        'html_url' => 'https://gitea.example.com',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/webhooks/source/gitea/redirect',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
});

function putGiteaOAuthState(string $state, int $giteaAppId, int $teamId): string
{
    $key = GiteaSource::oauthStateCacheKey($state);
    Cache::put($key, ['gitea_app_id' => $giteaAppId, 'team_id' => $teamId], now()->addMinutes(60));

    return $key;
}

describe('Gitea OAuth callback state validation', function () {
    test('rejects a callback without a state', function () {
        Http::fake();

        $this->get('/webhooks/source/gitea/redirect?code=any')->assertRedirect(route('source.all'));

        Http::assertNothingSent();
        expect($this->giteaApp->refresh()->access_token)->toBeNull();
    });

    test('rejects a callback whose state is the source UUID', function () {
        Http::fake();

        $this->get('/webhooks/source/gitea/redirect?code=any&state='.$this->giteaApp->uuid)
            ->assertRedirect(route('source.all'));

        Http::assertNothingSent();
        expect($this->giteaApp->refresh()->access_token)->toBeNull();
    });

    test('rejects an unknown or expired state', function () {
        Http::fake();

        $this->get('/webhooks/source/gitea/redirect?code=any&state=not-a-real-state')
            ->assertRedirect(route('source.all'));

        Http::assertNothingSent();
        expect($this->giteaApp->refresh()->access_token)->toBeNull();
    });

    test('rejects a state issued for a different team', function () {
        Http::fake();
        $otherTeam = Team::factory()->create();
        putGiteaOAuthState('state-for-other-team', $this->giteaApp->id, $otherTeam->id);

        $this->get('/webhooks/source/gitea/redirect?code=any&state=state-for-other-team')
            ->assertRedirect(route('source.all'));

        Http::assertNothingSent();
        expect($this->giteaApp->refresh()->access_token)->toBeNull();
    });

    test('rejects a callback from a team member who cannot administer the source', function () {
        Http::fake();
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);
        putGiteaOAuthState('member-state', $this->giteaApp->id, $this->team->id);

        $this->get('/webhooks/source/gitea/redirect?code=any&state=member-state')
            ->assertRedirect(route('source.all'));

        Http::assertNothingSent();
        expect($this->giteaApp->refresh()->access_token)->toBeNull();
    });

    test('requires authentication', function () {
        auth()->logout();
        session()->forget('currentTeam');

        $this->get('/webhooks/source/gitea/redirect?code=any&state=any')->assertRedirect(route('login'));
    });

    test('exchanges the code at the Gitea token endpoint, stores the tokens, and consumes the state', function () {
        Http::fake([
            'gitea.example.com/login/oauth/access_token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'token_type' => 'bearer',
            ]),
        ]);
        $key = putGiteaOAuthState('a-valid-state', $this->giteaApp->id, $this->team->id);

        $this->get('/webhooks/source/gitea/redirect?code=valid-code&state=a-valid-state')
            ->assertRedirect(route('source.gitea.show', ['gitea_app_uuid' => $this->giteaApp->uuid]));

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://gitea.example.com/login/oauth/access_token'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'valid-code'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['redirect_uri'] === 'https://coolify.example.com/webhooks/source/gitea/redirect');

        $fresh = $this->giteaApp->refresh();
        expect($fresh->access_token)->toBe('new-access-token')
            ->and($fresh->refresh_token)->toBe('new-refresh-token')
            ->and($fresh->expires_at)->toBeGreaterThanOrEqual(time() + 3590)
            ->and($fresh->expires_at)->toBeLessThanOrEqual(time() + 3600)
            ->and(Cache::get($key))->toBeNull();
    });

    test('does not store tokens when the token exchange fails', function () {
        Http::fake([
            'gitea.example.com/login/oauth/access_token' => Http::response(['error_description' => 'bad code'], 400),
        ]);
        putGiteaOAuthState('failing-state', $this->giteaApp->id, $this->team->id);

        $this->get('/webhooks/source/gitea/redirect?code=bad&state=failing-state')
            ->assertRedirect(route('source.gitea.show', ['gitea_app_uuid' => $this->giteaApp->uuid]))
            ->assertSessionHas('error', 'Gitea OAuth failed: bad code');

        expect($this->giteaApp->refresh()->access_token)->toBeNull();
    });
});
