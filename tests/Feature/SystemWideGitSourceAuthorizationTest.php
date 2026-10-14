<?php

use App\Livewire\Source\Github\Change as GithubChange;
use App\Livewire\Source\Github\Create as GithubCreate;
use App\Livewire\Source\Gitlab\Change as GitlabChange;
use App\Livewire\Source\Gitlab\Create as GitlabCreate;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function systemWideSourceTestPrivateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $privateKey);

    return $privateKey;
}

/**
 * @return array{team: Team, user: User, token: string, privateKey: PrivateKey}
 */
function systemWideSourceTestTeam(array $teamAttributes = []): array
{
    $team = Team::factory()->create($teamAttributes);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
    session(['currentTeam' => $team]);
    $token = $user->createToken('test-token', ['*'])->plainTextToken;

    $privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => systemWideSourceTestPrivateKey(),
        'team_id' => $team->id,
    ]);

    return compact('team', 'user', 'token', 'privateKey');
}

function systemWideSourceGithubPayload(PrivateKey $privateKey, array $overrides = []): array
{
    return array_merge([
        'name' => 'Team GitHub App',
        'html_url' => 'https://github.com',
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
        'private_key_uuid' => $privateKey->uuid,
    ], $overrides);
}

function systemWideSourceGithubApp(Team $team, PrivateKey $privateKey, bool $systemWide = false): GithubApp
{
    return GithubApp::create([
        'name' => 'Existing GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => $systemWide,
        'is_public' => false,
    ]);
}

function systemWideSourceGitlabApp(Team $team, bool $systemWide = false): GitlabApp
{
    return GitlabApp::create([
        'name' => 'Existing GitLab App',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $team->id,
        'is_system_wide' => $systemWide,
        'is_public' => false,
    ]);
}

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
});

describe('normal team admin', function () {
    beforeEach(function () {
        // Root team exists, but this admin is not a member of it.
        Team::factory()->create(['id' => 0]);
        [
            'team' => $this->team,
            'user' => $this->user,
            'token' => $this->token,
            'privateKey' => $this->privateKey,
        ] = systemWideSourceTestTeam();
    });

    test('cannot create a system-wide github app via api', function () {
        $this->withToken($this->token)
            ->postJson('/api/v1/github-apps', systemWideSourceGithubPayload($this->privateKey, ['is_system_wide' => true]))
            ->assertForbidden();

        expect(GithubApp::count())->toBe(0);
    });

    test('cannot create a system-wide gitlab app via api', function () {
        $this->withToken($this->token)
            ->postJson('/api/v1/gitlab-apps', [
                'name' => 'Team GitLab App',
                'html_url' => 'https://gitlab.com',
                'is_system_wide' => true,
            ])
            ->assertForbidden();

        expect(GitlabApp::count())->toBe(0);
    });

    test('can still create team-only sources via api', function () {
        $this->withToken($this->token)
            ->postJson('/api/v1/github-apps', systemWideSourceGithubPayload($this->privateKey, ['is_system_wide' => false]))
            ->assertCreated();
        $this->withToken($this->token)
            ->postJson('/api/v1/gitlab-apps', ['name' => 'Team GitLab App', 'html_url' => 'https://gitlab.com'])
            ->assertCreated();

        expect(GithubApp::sole()->is_system_wide)->toBeFalse()
            ->and(GitlabApp::sole()->is_system_wide)->toBeFalse();
    });

    test('cannot make an own github app system-wide via api', function () {
        $githubApp = systemWideSourceGithubApp($this->team, $this->privateKey);

        $this->withToken($this->token)
            ->patchJson("/api/v1/github-apps/{$githubApp->id}", ['is_system_wide' => true])
            ->assertForbidden();

        expect($githubApp->refresh()->is_system_wide)->toBeFalse();
    });

    test('cannot make an own gitlab app system-wide via api', function () {
        $gitlabApp = systemWideSourceGitlabApp($this->team);

        $this->withToken($this->token)
            ->patchJson("/api/v1/gitlab-apps/{$gitlabApp->id}", ['is_system_wide' => true])
            ->assertForbidden();

        expect($gitlabApp->refresh()->is_system_wide)->toBeFalse();
    });

    test('can still update team-only sources via api', function () {
        $githubApp = systemWideSourceGithubApp($this->team, $this->privateKey);
        $gitlabApp = systemWideSourceGitlabApp($this->team);

        $this->withToken($this->token)
            ->patchJson("/api/v1/github-apps/{$githubApp->id}", ['name' => 'Renamed', 'is_system_wide' => false])
            ->assertSuccessful();
        $this->withToken($this->token)
            ->patchJson("/api/v1/gitlab-apps/{$gitlabApp->id}", ['name' => 'Renamed', 'is_system_wide' => false])
            ->assertSuccessful();

        expect($githubApp->refresh()->name)->toBe('Renamed')
            ->and($githubApp->is_system_wide)->toBeFalse()
            ->and($gitlabApp->refresh()->name)->toBe('Renamed')
            ->and($gitlabApp->is_system_wide)->toBeFalse();
    });

    test('cannot create system-wide sources via livewire', function () {
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        Livewire::test(GithubCreate::class)
            ->set('name', 'shared-github')
            ->set('is_system_wide', true)
            ->call('createGitHubApp')
            ->assertDispatched('error');
        Livewire::test(GitlabCreate::class)
            ->set('name', 'shared-gitlab')
            ->set('is_system_wide', true)
            ->call('createGitLabApp')
            ->assertDispatched('error');

        expect(GithubApp::count())->toBe(0)
            ->and(GitlabApp::count())->toBe(0);
    });

    test('cannot make a github app system-wide via livewire', function (string $method) {
        $githubApp = systemWideSourceGithubApp($this->team, $this->privateKey);
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(GithubChange::class)
            ->set('isSystemWide', true)
            ->call($method)
            ->assertDispatched('error');

        expect($githubApp->refresh()->is_system_wide)->toBeFalse();
    })->with(['submit', 'instantSave']);

    test('cannot make a gitlab app system-wide via livewire', function (string $method) {
        $gitlabApp = systemWideSourceGitlabApp($this->team);
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        Livewire::withQueryParams(['gitlab_app_uuid' => $gitlabApp->uuid])
            ->test(GitlabChange::class)
            ->set('isSystemWide', true)
            ->call($method)
            ->assertDispatched('error');

        expect($gitlabApp->refresh()->is_system_wide)->toBeFalse();
    })->with(['submit', 'instantSave']);

    test('does not show the system-wide toggle', function () {
        $gitlabApp = systemWideSourceGitlabApp($this->team);
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        Livewire::test(GithubCreate::class)->assertDontSee('System wide');
        Livewire::test(GitlabCreate::class)->assertDontSee('System wide');
        Livewire::withQueryParams(['gitlab_app_uuid' => $gitlabApp->uuid])
            ->test(GitlabChange::class)
            ->assertDontSee('System wide');
    });
});

describe('root team admin', function () {
    beforeEach(function () {
        [
            'team' => $this->team,
            'user' => $this->user,
            'token' => $this->token,
            'privateKey' => $this->privateKey,
        ] = systemWideSourceTestTeam(['id' => 0]);
    });

    test('can create system-wide sources via api', function () {
        $this->withToken($this->token)
            ->postJson('/api/v1/github-apps', systemWideSourceGithubPayload($this->privateKey, ['is_system_wide' => true]))
            ->assertCreated();
        $this->withToken($this->token)
            ->postJson('/api/v1/gitlab-apps', [
                'name' => 'Shared GitLab App',
                'html_url' => 'https://gitlab.com',
                'is_system_wide' => true,
            ])
            ->assertCreated();

        expect(GithubApp::sole()->is_system_wide)->toBeTrue()
            ->and(GitlabApp::sole()->is_system_wide)->toBeTrue();
    });

    test('can make sources system-wide via api', function () {
        $githubApp = systemWideSourceGithubApp($this->team, $this->privateKey);
        $gitlabApp = systemWideSourceGitlabApp($this->team);

        $this->withToken($this->token)
            ->patchJson("/api/v1/github-apps/{$githubApp->id}", ['is_system_wide' => true])
            ->assertSuccessful();
        $this->withToken($this->token)
            ->patchJson("/api/v1/gitlab-apps/{$gitlabApp->id}", ['is_system_wide' => true])
            ->assertSuccessful();

        expect($githubApp->refresh()->is_system_wide)->toBeTrue()
            ->and($gitlabApp->refresh()->is_system_wide)->toBeTrue();
    });

    test('can make a gitlab app system-wide via livewire', function () {
        $gitlabApp = systemWideSourceGitlabApp($this->team);
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        Livewire::withQueryParams(['gitlab_app_uuid' => $gitlabApp->uuid])
            ->test(GitlabChange::class)
            ->assertSee('System wide')
            ->set('isSystemWide', true)
            ->call('instantSave')
            ->assertDispatched('success');

        expect($gitlabApp->refresh()->is_system_wide)->toBeTrue();
    });

    test('can create a system-wide github app via livewire', function () {
        $this->actingAs($this->user);
        session(['currentTeam' => $this->team]);

        Livewire::test(GithubCreate::class)
            ->assertSee('System wide')
            ->set('name', 'shared-github')
            ->set('is_system_wide', true)
            ->call('createGitHubApp')
            ->assertRedirect();

        expect(GithubApp::sole()->is_system_wide)->toBeTrue();
    });
});
