<?php

use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeGithubAppForRunnerGroupTests(): GithubApp
{
    $team = Team::factory()->create();

    $privateKey = PrivateKey::create([
        'name' => 'runner-group-key',
        'private_key' => <<<'KEY'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDMflQ+H/XBxrhK
3etBe1c4NzjOFcFp0EXbdhnZCPvkd7PE706osqnfTYxT5I2HYeBiXN20NVhxwhZy
f8K8sLuITPqjNfLkpwPwbHn5WAy4VgFOxrrVHlNo0jWYSLuNRQPtOgUBJc/WzDi7
PLPauCLE+sIK7i1dGf8f1UzBLJsNEKuGOq4uAhG8pjpkKY+vSFwgHNTK8qOtoauG
w+rz6fqzCJ9RLPo/SL7mXardeypg3roQZ9RNfCt50E4H+lP7+hLaDQk5IXBPpGZc
1ZpvQvAu+e+N62up4KwGFhxL3ziyr3djb7nmJpADwRbzKSl1ry50cpWFbgv9NOYO
lwfij9ErAgMBAAECggEACPfbWvQiM4gzCeQso+0JrgdMoEvM9TEzTG95V7mF+TGU
uo93htIlvWDUcCjHBN0dLu3SsqC09cbkyXW3782HvppdqEMT7sTdA9zGBqeUEJDZ
CCroA7O2Rb5o/Po88MefkfZS74dzKNZBAK57VsgaN5hQYpP/0k7zD42BCxHD5QaL
juEbQHl7/gthGZBez2IhuH3JcLRgLCXS9cEVCA7229uv0mNtFejZSbypIeq07qQf
iJgsaODtqL5avLj4JSxqjYUwv6oxkKDOK/XXurV2RQ0cV1upuV0Js0HgdQN2K1QL
h7VA2oO0K5++BoEX5Tn5aEvp0WVQF52wQ8w8pQ3TxQKBgQDqI6Tix5dUoLxLRbFZ
GjutQOOUpnmFqz/EioCs1Ll95tHC+qi/vyov1efWoufOR1CnLjrTE5Yls1FTYjpp
wTboxBmDYe473jqaZ4oKLZpXgN+Er6l4ktlw9m9MGx8/U891IKxYfdETj63yQOZK
4rQ4QS3qbY6N95H9T10azzG8zwKBgQDflhjZKz0ykvOV0TgvAOrqpC9TTteKqCue
q0Pma6utfWnhoYFwo7kmlBCRoLU4NB9UibJbIxERwTXEDlQMica0/rZStoB7UELn
9i9AlFPZUEO17TxYggG/TYDdj4MUNsoj3KZS1fGE4sQYi81pKsuy0y2tokZptKmG
mAVSKIJU5QKBgB7lZTSnschxDWfBYo2ncIiEL4PGE/MXjeqZfDFSQMfkVXmtKedj
imWVjGo+ROhrcLEe4JRJ2V5QM0MViy+5V02P0u4LViyAPqtxTj3ZlqxFTTltFKfc
eOT3H+ijC5SHsrB6B0QGFjjGlOWKutjW4YEq2Kw+mLkTGiia+GY5QQ7xAoGBAJs/
m61fyrSNOTnz9nEc0AFxU7Mi8aNDtlYMUa9zX9etV5HmFPzjkjJpaT/VOT/3YTHQ
EtoZdUbAw9aIpG+4UxNmMa8pLflx96MdXB4ZYEdq5jkyq05Bp3jwFeTCO6ATkzRn
h83I5FUDKGpq2IyHvL1EyVjhbscDPRtJ/5fWrPjJAoGBAN2Ejrbz3kIyJhf/m7Dq
JR7zmeeQmK/tAdG9mtIbPGZPUxQd7MOq2z02y3ZX5FJcWPFAuWTNFgs68T4CkeY4
8TUIdKEwhvkB0uR/alJVTLyaaGU8IOk7Rw6Otu9wlvjqy+Nqoy2GRS4VPLK9dePs
NwAXUicFB5gVAWeyU+C6Xjn1
-----END PRIVATE KEY-----
KEY,
        'team_id' => $team->id,
    ]);

    return GithubApp::create([
        'name' => 'Runner App',
        'app_id' => fake()->unique()->numberBetween(100000, 999999),
        'installation_id' => 222,
        'client_id' => 'Iv1.runner',
        'client_secret' => 'secret',
        'webhook_secret' => 'hook-secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
    ]);
}

function callEnsureRunnerGroup(ProvisionGithubRunnerJob $job, GithubApp $githubApp): int
{
    $method = new ReflectionMethod(ProvisionGithubRunnerJob::class, 'ensureRunnerGroup');
    $method->setAccessible(true);

    return $method->invoke($job, $githubApp);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('creates a runner group with the custom name when no runner group exists', function () {
    $githubApp = makeGithubAppForRunnerGroupTests();
    $githubApp->update(['runner_group_name' => 'Team Runner Group']);

    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/222/access_tokens' => Http::response(['token' => 'ghs_test'], 200),
        'https://api.github.com/orgs/test-org/actions/runner-groups' => Http::response(['id' => 9001], 201),
    ]);

    $job = new ProvisionGithubRunnerJob($githubApp->id, ['id' => 1], 'test-org');
    $runnerGroupId = callEnsureRunnerGroup($job, $githubApp->fresh());

    expect($runnerGroupId)->toBe(9001);

    $githubApp->refresh();
    expect($githubApp->runner_group_id)->toBe(9001)
        ->and($githubApp->runner_group_name)->toBe('Team Runner Group');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/orgs/test-org/actions/runner-groups'
            && $request['name'] === 'Team Runner Group';
    });
});

it('syncs the custom name to github when runner group id already exists', function () {
    $githubApp = makeGithubAppForRunnerGroupTests();
    $githubApp->update([
        'runner_group_id' => 42,
        'runner_group_name' => 'Synced Name',
    ]);

    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/222/access_tokens' => Http::response(['token' => 'ghs_test'], 200),
        'https://api.github.com/orgs/test-org/actions/runner-groups/42' => Http::response([], 200),
    ]);

    $job = new ProvisionGithubRunnerJob($githubApp->id, ['id' => 2], 'test-org');
    $runnerGroupId = callEnsureRunnerGroup($job, $githubApp->fresh());

    expect($runnerGroupId)->toBe(42);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'PATCH'
            && $request->url() === 'https://api.github.com/orgs/test-org/actions/runner-groups/42'
            && $request['name'] === 'Synced Name';
    });

    Http::assertNotSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/orgs/test-org/actions/runner-groups';
    });
});

it('recreates the runner group when the stored runner group id no longer exists on github', function () {
    $githubApp = makeGithubAppForRunnerGroupTests();
    $githubApp->update([
        'runner_group_id' => 42,
        'runner_group_name' => 'Recover Name',
    ]);

    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/222/access_tokens' => Http::response(['token' => 'ghs_test'], 200),
        'https://api.github.com/orgs/test-org/actions/runner-groups/42' => Http::response(['message' => 'Not Found'], 404),
        'https://api.github.com/orgs/test-org/actions/runner-groups' => Http::response(['id' => 96], 201),
    ]);

    $job = new ProvisionGithubRunnerJob($githubApp->id, ['id' => 3], 'test-org');
    $runnerGroupId = callEnsureRunnerGroup($job, $githubApp->fresh());

    expect($runnerGroupId)->toBe(96);

    $githubApp->refresh();
    expect($githubApp->runner_group_id)->toBe(96)
        ->and($githubApp->runner_group_name)->toBe('Recover Name');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'PATCH'
            && $request->url() === 'https://api.github.com/orgs/test-org/actions/runner-groups/42';
    });

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/orgs/test-org/actions/runner-groups'
            && $request['name'] === 'Recover Name';
    });
});

it('generates and stores a fallback name when no custom runner group name is set', function () {
    $githubApp = makeGithubAppForRunnerGroupTests();

    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/222/access_tokens' => Http::response(['token' => 'ghs_test'], 200),
        'https://api.github.com/orgs/test-org/actions/runner-groups' => Http::response(['id' => 77], 201),
    ]);

    $job = new ProvisionGithubRunnerJob($githubApp->id, ['id' => 4], 'test-org');
    $runnerGroupId = callEnsureRunnerGroup($job, $githubApp->fresh());

    expect($runnerGroupId)->toBe(77);

    $githubApp->refresh();
    expect($githubApp->runner_group_name)->toStartWith('Coolify-')
        ->and($githubApp->runner_group_id)->toBe(77);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/orgs/test-org/actions/runner-groups'
            && is_string($request['name'])
            && str_starts_with($request['name'], 'Coolify-');
    });
});
