<?php

use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Contract\EvaluateRequest;

it('derives different mirror keys per team for the same repository', function () {
    expect(EvaluateRequest::mirrorKeyFor(1, 'git@github.com:acme/mono.git'))
        ->not->toBe(EvaluateRequest::mirrorKeyFor(2, 'git@github.com:acme/mono.git'));
});

it('builds a deploy decision for fallbacks', function () {
    $decision = ApplicationDecision::deployBecause('web', 'evaluation_failed', 'The evaluation failed.');

    expect($decision->deploys())->toBeTrue()
        ->and($decision->toTriggerReason(str_repeat('b', 40)))->toMatchArray([
            'reason_code' => 'evaluation_failed',
            'reason' => 'The evaluation failed.',
            'after' => str_repeat('b', 40),
        ]);
});
