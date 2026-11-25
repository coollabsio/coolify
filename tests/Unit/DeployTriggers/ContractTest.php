<?php

use App\Services\DeployTriggers\Contract\AffectedContractException;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\EvaluateResponse;
use App\Services\DeployTriggers\Contract\ScanRequest;
use App\Services\DeployTriggers\Contract\ScanResponse;

function contractInput(string $id): EvaluateApplicationInput
{
    return new EvaluateApplicationInput(
        id: $id,
        baseCommit: str_repeat('a', 40),
        mergeBaseRef: null,
        mode: 'smart',
        watchPaths: null,
        buildPack: 'dockerfile',
        baseDirectory: '/',
        dockerfileLocation: '/apps/web/Dockerfile',
        dockerComposeLocation: null,
        commands: ['install' => null, 'build' => null, 'start' => null, 'compose_build' => null, 'compose_start' => null],
        referencePaths: ['content'],
        managedConfigPaths: [],
    );
}

function contractRequest(): EvaluateRequest
{
    return new EvaluateRequest(
        repositoryUrl: 'git@github.com:acme/mono.git',
        mirrorKey: EvaluateRequest::mirrorKeyFor(7, 'git@github.com:acme/mono.git'),
        gitEnv: ['GIT_SSH_COMMAND' => 'ssh -i /run/coolify/id_key'],
        gitConfig: [],
        after: str_repeat('b', 40),
        fetchRefs: ['refs/heads/main'],
        isFork: false,
        submodules: false,
        applications: [contractInput('web'), contractInput('api')],
    );
}

function contractFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__, 2)."/Fixtures/affected/responses/{$name}.json");
}

it('serializes the evaluate request in the contract shape', function () {
    $request = json_decode(contractRequest()->toJson(), true);

    expect($request['schema_version'])->toBe(1)
        ->and($request['repository']['url'])->toBe('git@github.com:acme/mono.git')
        ->and($request['repository']['mirror_key'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($request['repository']['git_env'])->toBe(['GIT_SSH_COMMAND' => 'ssh -i /run/coolify/id_key'])
        ->and($request['after'])->toBe(str_repeat('b', 40))
        ->and($request['fetch_refs'])->toBe(['refs/heads/main'])
        ->and($request['is_fork'])->toBeFalse()
        ->and($request['applications'][0]['mode'])->toBe('smart')
        ->and($request['applications'][0]['base_commit'])->toBe(str_repeat('a', 40))
        ->and($request['applications'][0]['dockerfile_location'])->toBe('/apps/web/Dockerfile')
        ->and($request['applications'][0]['reference_paths'])->toBe(['content'])
        ->and($request['limits']['max_mirror_bytes'])->toBe(2147483648)
        ->and($request['limits']['timeout_seconds'])->toBe(120)
        ->and($request['limits']['max_reported_files'])->toBe(200);
});

it('derives different mirror keys per team for the same repository', function () {
    expect(EvaluateRequest::mirrorKeyFor(1, 'git@github.com:acme/mono.git'))
        ->not->toBe(EvaluateRequest::mirrorKeyFor(2, 'git@github.com:acme/mono.git'));
});

it('parses a valid response', function () {
    $response = EvaluateResponse::fromJson(contractFixture('skip_and_deploy'), contractRequest());

    expect($response->after)->toBe(str_repeat('b', 40))
        ->and($response->applications['web']->deploys())->toBeFalse()
        ->and($response->applications['web']->reasonCode)->toBe('no_match')
        ->and($response->applications['api']->deploys())->toBeTrue()
        ->and($response->applications['api']->matches[0]['file'])->toBe('apps/api/a.ts')
        ->and($response->applications['api']->triggerSet['display'])->toBe(['apps/web/**']);
});

it('rejects a response that breaks the contract', function (string $fixture) {
    EvaluateResponse::fromJson(contractFixture($fixture), contractRequest());
})->with([
    'invalid json' => 'invalid_json',
    'schema version' => 'invalid_schema_version',
    'after is not a sha' => 'invalid_after',
    'missing application' => 'invalid_missing_application',
    'unknown application' => 'invalid_unknown_application',
    'unknown decision' => 'invalid_decision',
    'skip with an error reason' => 'invalid_skip_reason',
])->throws(AffectedContractException::class);

it('builds a deploy decision for fallbacks', function () {
    $decision = ApplicationDecision::deployBecause('web', 'evaluation_failed', 'The evaluation failed.');

    expect($decision->deploys())->toBeTrue()
        ->and($decision->toTriggerReason(str_repeat('b', 40)))->toMatchArray([
            'reason_code' => 'evaluation_failed',
            'reason' => 'The evaluation failed.',
            'after' => str_repeat('b', 40),
        ]);
});

it('serializes a scan request and parses a scan response', function () {
    $request = json_decode((new ScanRequest('/artifacts/x', str_repeat('c', 40), contractInput('web')))->toJson(), true);
    $response = ScanResponse::fromJson(json_encode([
        'schema_version' => 1, 'commit' => str_repeat('c', 40),
        'reference_paths' => ['content/blog'], 'evidence' => [], 'widened' => [], 'timings_ms' => ['total' => 1],
    ]));

    expect($request['worktree'])->toBe('/artifacts/x')
        ->and($request['application']['id'])->toBe('web')
        ->and($request['application'])->not->toHaveKey('base_commit')
        ->and($response->referencePaths)->toBe(['content/blog'])
        ->and($response->widened)->toBe([]);
});
