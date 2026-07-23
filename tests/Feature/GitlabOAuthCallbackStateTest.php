<?php

use App\Livewire\Source\Gitlab\Change as GitlabSource;
use App\Models\GitlabApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->gitlabApp = GitlabApp::create([
        'name' => 'Self-hosted GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/webhooks/source/gitlab/redirect',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
});

describe('GitLab OAuth callback state validation', function () {
    test('rejects a callback whose state is the source UUID (the old attack vector)', function () {
        Http::fake();

        $response = $this->get('/webhooks/source/gitlab/redirect?code=any&state='.$this->gitlabApp->uuid);

        $response->assertRedirect(route('source.all'));
        Http::assertNothingSent();
        expect($this->gitlabApp->refresh()->access_token)->toBeNull();
    });

    test('rejects a callback with an unknown / expired state and never exchanges the code', function () {
        Http::fake();

        $response = $this->get('/webhooks/source/gitlab/redirect?code=any&state=not-a-real-state');

        $response->assertRedirect(route('source.all'));
        Http::assertNothingSent();
        expect($this->gitlabApp->refresh()->access_token)->toBeNull();
    });

    test('rejects a state issued for a different team', function () {
        Http::fake();
        $otherTeam = Team::factory()->create();
        $state = 'state-for-other-team';
        Cache::put(GitlabSource::oauthStateCacheKey($state), [
            'gitlab_app_id' => $this->gitlabApp->id,
            'team_id' => $otherTeam->id,
        ], now()->addMinutes(60));

        $response = $this->get('/webhooks/source/gitlab/redirect?code=any&state='.$state);

        $response->assertRedirect(route('source.all'));
        Http::assertNothingSent();
        expect($this->gitlabApp->refresh()->access_token)->toBeNull();
    });

    test('accepts a valid one-time state, exchanges the code, and consumes the state', function () {
        Http::fake([
            '*/oauth/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 7200,
            ]),
        ]);

        $state = 'a-valid-server-issued-state';
        $key = GitlabSource::oauthStateCacheKey($state);
        Cache::put($key, [
            'gitlab_app_id' => $this->gitlabApp->id,
            'team_id' => $this->team->id,
        ], now()->addMinutes(60));

        $response = $this->get('/webhooks/source/gitlab/redirect?code=valid-code&state='.$state);

        $response->assertRedirect(route('source.gitlab.show', ['gitlab_app_uuid' => $this->gitlabApp->uuid]));

        $fresh = $this->gitlabApp->refresh();
        $fresh->makeVisible(['access_token', 'refresh_token']);
        expect($fresh->access_token)->toBe('new-access-token');
        expect($fresh->refresh_token)->toBe('new-refresh-token');

        // State must be single-use.
        expect(Cache::get($key))->toBeNull();
    });

    test('requires authentication', function () {
        auth()->logout();
        session()->forget('currentTeam');

        $response = $this->get('/webhooks/source/gitlab/redirect?code=any&state=any');

        $response->assertRedirect(route('login'));
    });

    test('rejects a callback from a team member who cannot administer the source', function () {
        Http::fake();

        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);

        $state = 'member-state';
        Cache::put(GitlabSource::oauthStateCacheKey($state), [
            'gitlab_app_id' => $this->gitlabApp->id,
            'team_id' => $this->team->id,
        ], now()->addMinutes(60));

        $response = $this->get('/webhooks/source/gitlab/redirect?code=any&state='.$state);

        $response->assertRedirect(route('source.all'));
        Http::assertNothingSent();
        expect($this->gitlabApp->refresh()->access_token)->toBeNull();
    });
});
