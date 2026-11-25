<?php

use App\Services\DeployTriggers\Contract\AffectedContractException;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\EvaluateResponse;

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
        managedConfigPaths: [],
    );
}

function contractRequest(): EvaluateRequest
{
    return new EvaluateRequest(
        repositoryUrl: 'git@github.com:acme/mono.git',
        mirrorKey: EvaluateRequest::mirrorKeyFor(7, 'git@github.com:acme/mono.git'),
        after: str_repeat('b', 40),
        fetchRefs: ['refs/heads/main'],
        applications: [contractInput('web'), contractInput('api')],
    );
}

function contractFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__, 2)."/Fixtures/affected/responses/{$name}.json");
}

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
