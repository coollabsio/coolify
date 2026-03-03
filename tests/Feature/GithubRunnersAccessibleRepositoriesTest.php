<?php

use App\Livewire\Server\GithubRunners;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// RSA-2048 key in PKCS8 PEM format — required by lcobucci/jwt Rsa\Sha256 signer
$validKey = "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDMflQ+H/XBxrhK\n3etBe1c4NzjOFcFp0EXbdhnZCPvkd7PE706osqnfTYxT5I2HYeBiXN20NVhxwhZy\nf8K8sLuITPqjNfLkpwPwbHn5WAy4VgFOxrrVHlNo0jWYSLuNRQPtOgUBJc/WzDi7\nPLPauCLE+sIK7i1dGf8f1UzBLJsNEKuGOq4uAhG8pjpkKY+vSFwgHNTK8qOtoauG\nw+rz6fqzCJ9RLPo/SL7mXardeypg3roQZ9RNfCt50E4H+lP7+hLaDQk5IXBPpGZc\n1ZpvQvAu+e+N62up4KwGFhxL3ziyr3djb7nmJpADwRbzKSl1ry50cpWFbgv9NOYO\nlwfij9ErAgMBAAECggEACPfbWvQiM4gzCeQso+0JrgdMoEvM9TEzTG95V7mF+TGU\nuo93htIlvWDUcCjHBN0dLu3SsqC09cbkyXW3782HvppdqEMT7sTdA9zGBqeUEJDZ\nCCroA7O2Rb5o/Po88MefkfZS74dzKNZBAK57VsgaN5hQYpP/0k7zD42BCxHD5QaL\njuEbQHl7/gthGZBez2IhuH3JcLRgLCXS9cEVCA7229uv0mNtFejZSbypIeq07qQf\niJgsaODtqL5avLj4JSxqjYUwv6oxkKDOK/XXurV2RQ0cV1upuV0Js0HgdQN2K1QL\nh7VA2oO0K5++BoEX5Tn5aEvp0WVQF52wQ8w8pQ3TxQKBgQDqI6Tix5dUoLxLRbFZ\nGjutQOOUpnmFqz/EioCs1Ll95tHC+qi/vyov1efWoufOR1CnLjrTE5Yls1FTYjpp\nwTboxBmDYe473jqaZ4oKLZpXgN+Er6l4ktlw9m9MGx8/U891IKxYfdETj63yQOZK\n4rQ4QS3qbY6N95H9T10azzG8zwKBgQDflhjZKz0ykvOV0TgvAOrqpC9TTteKqCue\nq0Pma6utfWnhoYFwo7kmlBCRoLU4NB9UibJbIxERwTXEDlQMica0/rZStoB7UELn\n9i9AlFPZUEO17TxYggG/TYDdj4MUNsoj3KZS1fGE4sQYi81pKsuy0y2tokZptKmG\nmAVSKIJU5QKBgB7lZTSnschxDWfBYo2ncIiEL4PGE/MXjeqZfDFSQMfkVXmtKedj\nimWVjGo+ROhrcLEe4JRJ2V5QM0MViy+5V02P0u4LViyAPqtxTj3ZlqxFTTltFKfc\neOT3H+ijC5SHsrB6B0QGFjjGlOWKutjW4YEq2Kw+mLkTGiia+GY5QQ7xAoGBAJs/\nm61fyrSNOTnz9nEc0AFxU7Mi8aNDtlYMUa9zX9etV5HmFPzjkjJpaT/VOT/3YTHQ\nEtoZdUbAw9aIpG+4UxNmMa8pLflx96MdXB4ZYEdq5jkyq05Bp3jwFeTCO6ATkzRn\nh83I5FUDKGpq2IyHvL1EyVjhbscDPRtJ/5fWrPjJAoGBAN2Ejrbz3kIyJhf/m7Dq\nJR7zmeeQmK/tAdG9mtIbPGZPUxQd7MOq2z02y3ZX5FJcWPFAuWTNFgs68T4CkeY4\n8TUIdKEwhvkB0uR/alJVTLyaaGU8IOk7Rw6Otu9wlvjqy+Nqoy2GRS4VPLK9dePs\nNwAXUicFB5gVAWeyU+C6Xjn1\n-----END PRIVATE KEY-----";

beforeEach(function () use ($validKey) {
    InstanceSettings::create(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => $validKey,
        'team_id' => $this->team->id,
    ]);

    $this->githubApp = GithubApp::create([
        'name' => 'Test App',
        'app_id' => 111,
        'installation_id' => 222,
        'client_id' => 'Iv1.abc',
        'client_secret' => 'secret',
        'webhook_secret' => 'hook-secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
        'organization_self_hosted_runners' => 'write',
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
});

describe('GithubRunners accessible repositories', function () {
    test('mount does not load repositories before frontend initialization', function () {
        GithubRunnerConfig::create([
            'server_id' => $this->server->id,
            'github_app_id' => $this->githubApp->id,
            'labels' => ['self-hosted', 'coolify'],
            'max_runners' => 4,
            'capacity_wait_timeout' => 60,
            'runner_user' => 'runner',
            'runner_base_dir' => '/opt/github-runners',
            'is_enabled' => true,
        ]);

        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/222/access_tokens' => Http::response([
                'token' => 'ghs_test_token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 200),
            'https://api.github.com/installation/repositories*' => Http::response([
                'total_count' => 1,
                'repositories' => [
                    ['full_name' => 'test-org/deferred-repo', 'name' => 'deferred-repo'],
                ],
            ], 200),
        ]);

        $component = Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->assertSet('selectedGithubAppId', $this->githubApp->id)
            ->assertSet('repositoriesLoaded', false)
            ->assertSet('accessibleRepositories', []);

        Http::assertNothingSent();

        $component->call('initializeRepositories')
            ->assertSet('repositoriesLoaded', true)
            ->assertSet('accessibleRepositories', ['test-org/deferred-repo']);
    });

    test('initializeRepositories loads repositories only once for preselected app', function () {
        GithubRunnerConfig::create([
            'server_id' => $this->server->id,
            'github_app_id' => $this->githubApp->id,
            'labels' => ['self-hosted', 'coolify'],
            'max_runners' => 4,
            'capacity_wait_timeout' => 60,
            'runner_user' => 'runner',
            'runner_base_dir' => '/opt/github-runners',
            'is_enabled' => true,
        ]);

        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/222/access_tokens' => Http::response([
                'token' => 'ghs_test_token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 200),
            'https://api.github.com/installation/repositories*' => Http::response([
                'total_count' => 1,
                'repositories' => [
                    ['full_name' => 'test-org/only-once', 'name' => 'only-once'],
                ],
            ], 200),
        ]);

        Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->assertSet('selectedGithubAppId', $this->githubApp->id)
            ->call('initializeRepositories')
            ->assertSet('accessibleRepositories', ['test-org/only-once']);

        Http::assertSentCount(3);
    });

    test('loadAccessibleRepositories populates accessibleRepositories from GitHub API', function () {
        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/222/access_tokens' => Http::response([
                'token' => 'ghs_test_token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 200),
            'https://api.github.com/installation/repositories*' => Http::response([
                'total_count' => 2,
                'repositories' => [
                    ['full_name' => 'test-org/repo-b', 'name' => 'repo-b'],
                    ['full_name' => 'test-org/repo-a', 'name' => 'repo-a'],
                ],
            ], 200),
        ]);

        Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('selectedGithubAppId', $this->githubApp->id)
            ->call('loadAccessibleRepositories')
            ->assertSet('repositoryError', null)
            ->assertSet('accessibleRepositories', ['test-org/repo-a', 'test-org/repo-b']);
    });

    test('loadAccessibleRepositories sorts repositories alphabetically', function () {
        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/222/access_tokens' => Http::response([
                'token' => 'ghs_test_token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 200),
            'https://api.github.com/installation/repositories*' => Http::response([
                'total_count' => 3,
                'repositories' => [
                    ['full_name' => 'test-org/zebra', 'name' => 'zebra'],
                    ['full_name' => 'test-org/alpha', 'name' => 'alpha'],
                    ['full_name' => 'test-org/middle', 'name' => 'middle'],
                ],
            ], 200),
        ]);

        Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('selectedGithubAppId', $this->githubApp->id)
            ->call('loadAccessibleRepositories')
            ->assertSet('accessibleRepositories', ['test-org/alpha', 'test-org/middle', 'test-org/zebra']);
    });

    test('loadAccessibleRepositories returns empty list when no repos accessible', function () {
        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/222/access_tokens' => Http::response([
                'token' => 'ghs_test_token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 200),
            'https://api.github.com/installation/repositories*' => Http::response([
                'total_count' => 0,
                'repositories' => [],
            ], 200),
        ]);

        Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('selectedGithubAppId', $this->githubApp->id)
            ->call('loadAccessibleRepositories')
            ->assertSet('repositoryError', null)
            ->assertSet('accessibleRepositories', []);
    });

    test('loadAccessibleRepositories does nothing when no app is selected', function () {
        Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->call('loadAccessibleRepositories')
            ->assertSet('accessibleRepositories', [])
            ->assertSet('repositoryError', null);
    });

    test('loadAccessibleRepositories does nothing when app has no installation_id', function () {
        $this->githubApp->update(['installation_id' => null]);

        Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('selectedGithubAppId', $this->githubApp->id)
            ->call('loadAccessibleRepositories')
            ->assertSet('accessibleRepositories', [])
            ->assertSet('repositoryError', null);
    });

    test('changing selectedGithubAppId triggers repository reload', function () {
        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/222/access_tokens' => Http::response([
                'token' => 'ghs_test_token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 200),
            'https://api.github.com/installation/repositories*' => Http::response([
                'total_count' => 1,
                'repositories' => [
                    ['full_name' => 'test-org/my-repo', 'name' => 'my-repo'],
                ],
            ], 200),
        ]);

        Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
            ->set('selectedGithubAppId', $this->githubApp->id)
            ->assertSet('accessibleRepositories', ['test-org/my-repo']);
    });
});
