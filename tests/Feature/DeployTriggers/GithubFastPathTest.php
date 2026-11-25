<?php

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Services\DeployTriggers\GithubFastPath;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\TriggerCandidate;
use App\Services\DeployTriggers\TriggerFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const FAST_BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const FAST_AFTER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function () {
    Cache::flush();
    $this->fixture = deployTriggerFixture();
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    $this->githubApp = GithubApp::create([
        'name' => 'fast-path-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'app_id' => 1234, 'installation_id' => 67890, 'webhook_secret' => 'secret', 'is_public' => false,
        'private_key_id' => PrivateKey::create(['name' => 'k', 'private_key' => $pem, 'team_id' => $this->fixture['team']->id])->id,
        'team_id' => $this->fixture['team']->id,
    ]);
    $this->application = deployTriggerApplication($this->fixture, [
        'base_directory' => '/apps/web', 'source_id' => $this->githubApp->id, 'source_type' => GithubApp::class,
    ]);
    $this->application->forceFill(['trigger_set' => fastPathSet(TriggerFingerprint::for($this->application))])->save();
});

function fastPathSet(string $fingerprint, array $overrides = []): array
{
    return array_merge([
        'compiled' => ['version' => 1, 'all_of' => [['limit' => 'build_context', 'default' => 'exclude', 'rules' => [['action' => 'include', 'kind' => 'dir', 'path' => 'apps/web']]]], 'lockfiles' => ['pnpm-lock.yaml']],
        'input_patterns' => ['version' => 1, 'all_of' => [['limit' => 'inputs', 'default' => 'exclude', 'rules' => [['action' => 'include', 'kind' => 'glob', 'pattern' => '**/package.json']]]], 'lockfiles' => []],
        'display' => ['apps/web/**'],
        'evaluated_commit' => FAST_BASE,
        'config_fingerprint' => $fingerprint,
        'last_error' => null,
    ], $overrides);
}

function fastPathContext(array $overrides = []): PushContext
{
    return new PushContext(...array_merge([
        'provider' => 'github', 'teamId' => 1, 'repositoryUrl' => 'https://github.com/acme/mono.git', 'ref' => 'refs/heads/main',
        'after' => FAST_AFTER, 'before' => FAST_BASE, 'forced' => false, 'commitMessage' => null, 'githubAppId' => 1,
        'repositoryFullName' => 'acme/mono', 'payloadChangedFiles' => null, 'pullRequest' => null,
    ], $overrides));
}

function fastCandidate($application, array $overrides = []): TriggerCandidate
{
    return new TriggerCandidate(...array_merge([
        'id' => $application->uuid, 'application' => $application, 'preview' => null, 'mode' => 'smart',
        'base' => FAST_BASE, 'isStored' => true, 'fingerprint' => TriggerFingerprint::for($application),
    ], $overrides));
}

function fakeCompare(string $status, array $files, int $times = 1): void
{
    Http::fake([
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        'https://api.github.com/repos/acme/mono/compare/*' => Http::response(['status' => $status, 'files' => array_map(fn ($f) => ['filename' => $f], $files)]),
    ]);
}

function fastDecide($test, array $candidates, ?PushContext $context = null): array
{
    return app(GithubFastPath::class)->decide(collect($candidates)->keyBy('id')->all(), $context ?? fastPathContext(['githubAppId' => $test->githubApp->id]));
}

it('skips when no changed file is in the set', function () {
    fakeCompare('ahead', ['apps/api/a.ts']);

    $decision = fastDecide($this, [fastCandidate($this->application)])[$this->application->uuid];

    expect($decision->deploys())->toBeFalse()
        ->and($decision->reasonCode)->toBe('no_match')
        ->and($decision->triggerSet['display'])->toBe(['apps/web/**']);
});

it('deploys and records the matching file', function () {
    fakeCompare('ahead', ['apps/api/a.ts', 'apps/web/page.tsx']);

    $decision = fastDecide($this, [fastCandidate($this->application)])[$this->application->uuid];

    expect($decision->deploys())->toBeTrue()
        ->and($decision->reasonCode)->toBe('matched')
        ->and($decision->matches[0]['file'])->toBe('apps/web/page.tsx');
});

it('deploys an identical compare as an empty diff', function () {
    fakeCompare('identical', []);

    expect(fastDecide($this, [fastCandidate($this->application)])[$this->application->uuid]->reasonCode)->toBe('empty_diff');
});

it('uses the legacy matcher for watch paths mode', function () {
    fakeCompare('ahead', ['docs/a.md']);
    $this->application->watch_paths = 'apps/web/**';

    $decision = fastDecide($this, [fastCandidate($this->application, ['mode' => 'watch_paths'])])[$this->application->uuid];

    expect($decision->reasonCode)->toBe('watch_paths_no_match');
});

it('leaves the decision to the tool when', function (array $candidate, array $context, string $status, array $files, array $set) {
    fakeCompare($status, $files);
    if ($set !== []) {
        $this->application->forceFill(['trigger_set' => fastPathSet(TriggerFingerprint::for($this->application), $set)])->save();
    }

    $decisions = fastDecide($this, [fastCandidate($this->application, $candidate)], fastPathContext(array_merge(['githubAppId' => $this->githubApp->id], $context)));

    expect($decisions)->toBe([]);
})->with([
    'forced push' => [[], ['forced' => true], 'ahead', ['apps/api/a.ts'], []],
    'not github' => [[], ['provider' => 'gitlab'], 'ahead', ['apps/api/a.ts'], []],
    'manual github webhook' => [[], ['githubAppId' => null], 'ahead', ['apps/api/a.ts'], []],
    'diverged history' => [[], [], 'diverged', ['apps/api/a.ts'], []],
    'an input changed' => [[], [], 'ahead', ['apps/api/package.json'], []],
    'a lockfile changed' => [[], [], 'ahead', ['pnpm-lock.yaml'], []],
    'fingerprint changed' => [['fingerprint' => 'other'], [], 'ahead', ['apps/api/a.ts'], []],
    'set from another commit' => [[], [], 'ahead', ['apps/api/a.ts'], ['evaluated_commit' => str_repeat('c', 40)]],
    'last evaluation failed' => [[], [], 'ahead', ['apps/api/a.ts'], ['last_error' => 'timeout']],
    'no base' => [['base' => null], [], 'ahead', ['apps/api/a.ts'], []],
]);

it('leaves the decision to the tool when the compare hits 300 files', function () {
    fakeCompare('ahead', array_map(fn (int $i) => "apps/api/f{$i}.ts", range(1, 300)));

    expect(fastDecide($this, [fastCandidate($this->application)]))->toBe([]);
});

it('calls compare once per distinct base', function () {
    fakeCompare('ahead', ['apps/api/a.ts']);
    $second = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/docs', 'source_id' => $this->githubApp->id, 'source_type' => GithubApp::class]);
    $second->forceFill(['trigger_set' => fastPathSet(TriggerFingerprint::for($second))])->save();

    $decisions = fastDecide($this, [fastCandidate($this->application), fastCandidate($second)]);

    expect($decisions)->toHaveCount(2);
    Http::assertSentCount(2);
});

it('treats a pull request file list without a github app as unknown', function () {
    expect(getGithubPullRequestFiles(null, 'acme', 'mono', 1))->toBeNull()
        ->and(getGithubCommitRangeFiles(null, 'acme', 'mono', FAST_BASE, FAST_AFTER))->toBeNull();
});
