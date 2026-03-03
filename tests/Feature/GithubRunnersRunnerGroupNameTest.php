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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

$validKey = "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDMflQ+H/XBxrhK\n3etBe1c4NzjOFcFp0EXbdhnZCPvkd7PE706osqnfTYxT5I2HYeBiXN20NVhxwhZy\nf8K8sLuITPqjNfLkpwPwbHn5WAy4VgFOxrrVHlNo0jWYSLuNRQPtOgUBJc/WzDi7\nPLPauCLE+sIK7i1dGf8f1UzBLJsNEKuGOq4uAhG8pjpkKY+vSFwgHNTK8qOtoauG\nw+rz6fqzCJ9RLPo/SL7mXardeypg3roQZ9RNfCt50E4H+lP7+hLaDQk5IXBPpGZc\n1ZpvQvAu+e+N62up4KwGFhxL3ziyr3djb7nmJpADwRbzKSl1ry50cpWFbgv9NOYO\nlwfij9ErAgMBAAECggEACPfbWvQiM4gzCeQso+0JrgdMoEvM9TEzTG95V7mF+TGU\nuo93htIlvWDUcCjHBN0dLu3SsqC09cbkyXW3782HvppdqEMT7sTdA9zGBqeUEJDZ\nCCroA7O2Rb5o/Po88MefkfZS74dzKNZBAK57VsgaN5hQYpP/0k7zD42BCxHD5QaL\njuEbQHl7/gthGZBez2IhuH3JcLRgLCXS9cEVCA7229uv0mNtFejZSbypIeq07qQf\niJgsaODtqL5avLj4JSxqjYUwv6oxkKDOK/XXurV2RQ0cV1upuV0Js0HgdQN2K1QL\nh7VA2oO0K5++BoEX5Tn5aEvp0WVQF52wQ8w8pQ3TxQKBgQDqI6Tix5dUoLxLRbFZ\nGjutQOOUpnmFqz/EioCs1Ll95tHC+qi/vyov1efWoufOR1CnLjrTE5Yls1FTYjpp\nwTboxBmDYe473jqaZ4oKLZpXgN+Er6l4ktlw9m9MGx8/U891IKxYfdETj63yQOZK\n4rQ4QS3qbY6N95H9T10azzG8zwKBgQDflhjZKz0ykvOV0TgvAOrqpC9TTteKqCue\nq0Pma6utfWnhoYFwo7kmlBCRoLU4NB9UibJbIxERwTXEDlQMica0/rZStoB7UELn\n9i9AlFPZUEO17TxYggG/TYDdj4MUNsoj3KZS1fGE4sQYi81pKsuy0y2tokZptKmG\nmAVSKIJU5QKBgB7lZTSnschxDWfBYo2ncIiEL4PGE/MXjeqZfDFSQMfkVXmtKedj\nimWVjGo+ROhrcLEe4JRJ2V5QM0MViy+5V02P0u4LViyAPqtxTj3ZlqxFTTltFKfc\neOT3H+ijC5SHsrB6B0QGFjjGlOWKutjW4YEq2Kw+mLkTGiia+GY5QQ7xAoGBAJs/\nm61fyrSNOTnz9nEc0AFxU7Mi8aNDtlYMUa9zX9etV5HmFPzjkjJpaT/VOT/3YTHQ\nEtoZdUbAw9aIpG+4UxNmMa8pLflx96MdXB4ZYEdq5jkyq05Bp3jwFeTCO6ATkzRn\nh83I5FUDKGpq2IyHvL1EyVjhbscDPRtJ/5fWrPjJAoGBAN2Ejrbz3kIyJhf/m7Dq\nJR7zmeeQmK/tAdG9mtIbPGZPUxQd7MOq2z02y3ZX5FJcWPFAuWTNFgs68T4CkeY4\n8TUIdKEwhvkB0uR/alJVTLyaaGU8IOk7Rw6Otu9wlvjqy+Nqoy2GRS4VPLK9dePs\nNwAXUicFB5gVAWeyU+C6Xjn1\n-----END PRIVATE KEY-----";

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKeyId = DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => encrypt('test-key-content'),
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->githubApp = GithubApp::create([
        'name' => 'Test App',
        'app_id' => 123456,
        'installation_id' => null,
        'client_id' => 'Iv1.abc',
        'client_secret' => 'secret',
        'webhook_secret' => 'hook-secret',
        'private_key_id' => $privateKeyId,
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
        'organization_self_hosted_runners' => 'write',
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKeyId,
    ]);
});

it('stores the normalized runner group name when saving github runner config', function () {
    Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedGithubAppId', $this->githubApp->id)
        ->set('runnerGroupName', '   Team    Runners   Primary   ')
        ->call('submit')
        ->assertHasNoErrors();

    $this->githubApp->refresh();

    expect($this->githubApp->runner_group_name)->toBe('Team Runners Primary');
});

it('generates a default runner group name when the field is empty', function () {
    $this->githubApp->update(['runner_group_name' => 'Existing Name']);

    Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedGithubAppId', $this->githubApp->id)
        ->set('runnerGroupName', '   ')
        ->call('submit')
        ->assertHasNoErrors();

    $this->githubApp->refresh();

    expect($this->githubApp->runner_group_name)->toStartWith('Coolify-');
});

it('syncs runner group name to github api when saving from ui', function () use ($validKey) {
    $validPrivateKey = PrivateKey::create([
        'name' => 'valid-runner-key',
        'private_key' => $validKey,
        'team_id' => $this->team->id,
    ]);

    $this->githubApp->update([
        'installation_id' => 222,
        'private_key_id' => $validPrivateKey->id,
        'runner_group_id' => 88,
        'runner_group_name' => 'Old Name',
    ]);

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

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/222/access_tokens' => Http::response(['token' => 'ghs_test_token'], 200),
        'https://api.github.com/orgs/test-org/actions/runner-groups/88' => Http::response([], 200),
    ]);

    Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('selectedGithubAppId', $this->githubApp->id)
        ->set('selectedGithubAppId', $this->githubApp->id)
        ->set('runnerGroupName', 'New Synced Name')
        ->call('submit')
        ->assertHasNoErrors();

    $this->githubApp->refresh();
    expect($this->githubApp->runner_group_name)->toBe('New Synced Name');

    $recordedRequests = collect(Http::recorded())
        ->map(fn (array $entry) => $entry[0]->method().' '.$entry[0]->url())
        ->values()
        ->all();

    \PHPUnit\Framework\Assert::assertContains(
        'PATCH https://api.github.com/orgs/test-org/actions/runner-groups/88',
        $recordedRequests,
        'Recorded requests: '.json_encode($recordedRequests)
    );
});

it('does not sync runner group name to github api when field is not dirty', function () use ($validKey) {
    $validPrivateKey = PrivateKey::create([
        'name' => 'valid-runner-key-no-dirty',
        'private_key' => $validKey,
        'team_id' => $this->team->id,
    ]);

    $this->githubApp->update([
        'installation_id' => 222,
        'private_key_id' => $validPrivateKey->id,
        'runner_group_id' => 88,
        'runner_group_name' => 'Same Name',
    ]);

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

    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('selectedGithubAppId', $this->githubApp->id)
        ->assertSet('runnerGroupName', 'Same Name')
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertNothingSent();
});

it('generates and syncs a default runner group name to github when field is empty', function () use ($validKey) {
    $validPrivateKey = PrivateKey::create([
        'name' => 'valid-runner-key-empty-sync',
        'private_key' => $validKey,
        'team_id' => $this->team->id,
    ]);

    $this->githubApp->update([
        'installation_id' => 222,
        'private_key_id' => $validPrivateKey->id,
        'runner_group_id' => 88,
        'runner_group_name' => 'Existing Name',
    ]);

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

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/222/access_tokens' => Http::response(['token' => 'ghs_test_token'], 200),
        'https://api.github.com/orgs/test-org/actions/runner-groups/88' => Http::response([], 200),
    ]);

    Livewire::test(GithubRunners::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('selectedGithubAppId', $this->githubApp->id)
        ->set('runnerGroupName', '   ')
        ->call('submit')
        ->assertHasNoErrors();

    $this->githubApp->refresh();
    expect($this->githubApp->runner_group_name)->toStartWith('Coolify-');

    $recordedRequests = collect(Http::recorded())
        ->map(fn (array $entry) => $entry[0]->method().' '.$entry[0]->url())
        ->values()
        ->all();

    \PHPUnit\Framework\Assert::assertContains(
        'PATCH https://api.github.com/orgs/test-org/actions/runner-groups/88',
        $recordedRequests,
        'Recorded requests: '.json_encode($recordedRequests)
    );
});
