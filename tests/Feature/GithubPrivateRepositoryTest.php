<?php

use App\Livewire\Project\New\GithubPrivateRepository;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->rsaKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($this->rsaKey, $pemKey);

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => $pemKey,
        'team_id' => $this->team->id,
    ]);

    $this->githubApp = GithubApp::create([
        'name' => 'Test GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'webhook_secret' => 'test-webhook-secret',
        'private_key_id' => $this->privateKey->id,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);
});

function fakeGithubHttp(array $repositories): void
{
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
            'token' => 'fake-installation-token',
        ], 201),
        'https://api.github.com/installation/repositories*' => Http::response([
            'total_count' => count($repositories),
            'repositories' => $repositories,
        ], 200),
    ]);
}

function githubPrivateRepositoryTestPrivateKeyForTeam(Team $team): PrivateKey
{
    $rsaKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($rsaKey, $pemKey);

    return PrivateKey::create([
        'name' => 'Test Key '.$team->id,
        'private_key' => $pemKey,
        'team_id' => $team->id,
    ]);
}

describe('GitHub Private Repository Component', function () {
    test('loadRepositories fetches and displays repositories', function () {
        $repos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
            ['id' => 2, 'name' => 'beta-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($repos);

        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->assertSet('current_step', 'github_apps')
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSet('current_step', 'repository')
            ->assertSet('total_repositories_count', 2)
            ->assertSet('selected_repository_id', 1);
    });

    test('loadRepositories rejects a github app owned by another team', function () {
        $victimTeam = Team::factory()->create();
        $victimPrivateKey = githubPrivateRepositoryTestPrivateKeyForTeam($victimTeam);
        $victimGithubApp = GithubApp::create([
            'name' => 'Victim GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 54321,
            'installation_id' => 98765,
            'client_id' => 'victim-client-id',
            'client_secret' => 'victim-client-secret',
            'webhook_secret' => 'victim-webhook-secret',
            'private_key_id' => $victimPrivateKey->id,
            'team_id' => $victimTeam->id,
            'is_public' => false,
            'is_system_wide' => false,
        ]);

        Http::fake();

        expect(fn () => Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->call('loadRepositories', $victimGithubApp->id)
        )->toThrow(ModelNotFoundException::class);

        Http::assertNothingSent();
    });

    test('mount lists another teams system wide github app', function () {
        $victimTeam = Team::factory()->create();
        $victimPrivateKey = githubPrivateRepositoryTestPrivateKeyForTeam($victimTeam);
        $systemWideGithubApp = GithubApp::create([
            'name' => 'System Wide GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 54321,
            'installation_id' => 98765,
            'client_id' => 'system-client-id',
            'client_secret' => 'system-client-secret',
            'webhook_secret' => 'system-webhook-secret',
            'private_key_id' => $victimPrivateKey->id,
            'team_id' => $victimTeam->id,
            'is_public' => false,
            'is_system_wide' => true,
        ]);

        $component = Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app']);

        expect($component->get('github_apps')->pluck('id')->all())
            ->toContain($this->githubApp->id)
            ->toContain($systemWideGithubApp->id);
    });

    test('loadRepositories can use another teams system wide github app', function () {
        $victimTeam = Team::factory()->create();
        $victimPrivateKey = githubPrivateRepositoryTestPrivateKeyForTeam($victimTeam);
        $systemWideGithubApp = GithubApp::create([
            'name' => 'System Wide GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 54321,
            'installation_id' => 67890,
            'client_id' => 'system-client-id',
            'client_secret' => 'system-client-secret',
            'webhook_secret' => 'system-webhook-secret',
            'private_key_id' => $victimPrivateKey->id,
            'team_id' => $victimTeam->id,
            'is_public' => false,
            'is_system_wide' => true,
        ]);
        $repos = [
            ['id' => 1, 'name' => 'system-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($repos);

        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->call('loadRepositories', $systemWideGithubApp->id)
            ->assertSet('current_step', 'repository')
            ->assertSet('total_repositories_count', 1)
            ->assertSet('selected_repository_id', 1);
    });

    test('github installation token is not stored as public component state', function () {
        expect((new ReflectionClass(GithubPrivateRepository::class))->hasProperty('token'))->toBeFalse();
    });

    test('selected github app id cannot be tampered with from the client', function () {
        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->set('selected_github_app_id', $this->githubApp->id);
    })->throws(CannotUpdateLockedPropertyException::class);

    test('loadRepositories can be called again to refresh the repository list', function () {
        $initialRepos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
        ];

        $updatedRepos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
            ['id' => 2, 'name' => 'beta-repo', 'owner' => ['login' => 'testuser']],
            ['id' => 3, 'name' => 'gamma-repo', 'owner' => ['login' => 'testuser']],
        ];

        $callCount = 0;
        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
                'Date' => now()->toRfc7231String(),
            ]),
            'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
                'token' => 'fake-installation-token',
            ], 201),
            'https://api.github.com/installation/repositories*' => function () use (&$callCount, $initialRepos, $updatedRepos) {
                $callCount++;
                $repos = $callCount === 1 ? $initialRepos : $updatedRepos;

                return Http::response([
                    'total_count' => count($repos),
                    'repositories' => $repos,
                ], 200);
            },
        ]);

        $component = Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSet('total_repositories_count', 1);

        // Simulate new repos becoming available after changing access on GitHub
        $component
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSet('total_repositories_count', 3)
            ->assertSet('current_step', 'repository');
    });

    test('loadRepositories resets branches when refreshing', function () {
        $repos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($repos);

        $component = Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->call('loadRepositories', $this->githubApp->id);

        // Manually set branches to simulate a previous branch load
        $component->set('branches', collect([['name' => 'main'], ['name' => 'develop']]));
        $component->set('total_branches_count', 2);

        // Refresh repositories should reset branches
        fakeGithubHttp($repos);

        $component
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSet('total_branches_count', 0)
            ->assertSet('branches', collect());
    });

    test('refresh button is visible when repositories are loaded', function () {
        $repos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($repos);

        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSee('Refresh Repository List');
    });

    test('refresh button is not visible before repositories are loaded', function () {
        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->assertDontSee('Refresh Repository List');
    });
});
