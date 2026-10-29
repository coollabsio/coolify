<?php

use App\Models\ImageScanIgnore;
use App\Services\ImageScan\CandidateEvaluator;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;
use App\Services\ImageScan\IgnoreMatcher;

const EVALUATOR_EOL = ['name' => 'alpine', 'version' => '3.16.2'];

/**
 * @return list<Finding>
 */
function evaluatorFixture(string $name): array
{
    return (new GrypeReportParser)->parse(file_get_contents(dirname(__DIR__, 2)."/Fixtures/image-scan/{$name}.ndjson"))['findings'];
}

function evaluatorFinding(string $id, string $severity = 'high', bool $kev = false, array $aliases = [], ?float $epss = null, ?float $score = null): Finding
{
    return Finding::fromArray([
        'id' => $id, 'aliases' => $aliases, 'severity' => $severity, 'package' => 'openssl', 'version' => '1.0', 'type' => 'apk',
        'artifactId' => 'a', 'fixedIn' => '1.1', 'fixState' => 'fixed', 'kev' => $kev, 'epss' => $epss, 'cvssScore' => $score,
    ]);
}

function evaluatorIgnores(string ...$ids): IgnoreMatcher
{
    return IgnoreMatcher::fromIgnores(collect(array_map(fn (string $id) => new ImageScanIgnore(['reason' => 'test', 'vulnerability_id' => $id]), $ids)));
}

it('does not recommend 15.11-alpine: it adds findings with an exploit signal that 15.0-alpine does not have', function () {
    $evaluator = new CandidateEvaluator;

    expect($evaluator->isBetter(evaluatorFixture('grype'), evaluatorFixture('grype-candidate-15.11'), evaluatorIgnores(), EVALUATOR_EOL, null))->toBeFalse();
});

it('recommends 15-alpine although it adds critical Go findings in gosu: it fixes an exploitable SQL injection', function () {
    $evaluator = new CandidateEvaluator;
    $ignores = evaluatorIgnores();
    $current = evaluatorFixture('grype');
    $candidate = evaluatorFixture('grype-candidate-15-alpine');
    $fixed = $evaluator->fixedIds($current, $candidate, $ignores);

    expect($evaluator->isBetter($current, $candidate, $ignores, EVALUATOR_EOL, null))->toBeTrue()
        ->and($evaluator->isBetter($current, $candidate, $ignores, null, null))->toBeTrue()
        ->and($fixed)->toContain('CVE-2025-1094')
        ->and($fixed)->not->toContain('CVE-2023-2650');
});

it('lists only the relevant findings the candidate no longer has under any of their IDs', function () {
    $evaluator = new CandidateEvaluator;
    $current = [
        evaluatorFinding('GHSA-1111', aliases: ['CVE-2024-1']),
        evaluatorFinding('GHSA-2222', aliases: ['CVE-2024-2']),
        evaluatorFinding('CVE-2024-3', 'medium'),
        evaluatorFinding('CVE-2024-4', 'critical'),
    ];
    $candidate = [evaluatorFinding('CVE-2024-1')];

    expect($evaluator->fixedIds($current, $candidate, evaluatorIgnores('CVE-2024-4')))->toBe(['GHSA-2222', 'CVE-2024-2']);
});

it('counts a candidate with fewer relevant findings as better, also when it adds a critical finding', function () {
    $evaluator = new CandidateEvaluator;
    $current = [evaluatorFinding('CVE-1', 'high'), evaluatorFinding('CVE-2', 'high'), evaluatorFinding('CVE-3', 'medium')];

    expect($evaluator->isBetter($current, [evaluatorFinding('CVE-1', 'high')], evaluatorIgnores(), null, null))->toBeTrue()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-9', 'critical')], evaluatorIgnores(), null, null))->toBeTrue()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-1', 'high'), evaluatorFinding('CVE-9', 'medium'), evaluatorFinding('CVE-8', 'low')], evaluatorIgnores(), null, null))->toBeTrue();
});

it('does not count a candidate with as many relevant findings and no fixed exploit signal as better', function () {
    $evaluator = new CandidateEvaluator;
    $current = [evaluatorFinding('CVE-1', 'high'), evaluatorFinding('CVE-2', 'medium')];

    expect($evaluator->isBetter($current, [evaluatorFinding('CVE-1', 'high')], evaluatorIgnores(), null, null))->toBeFalse()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-3', 'high')], evaluatorIgnores(), null, null))->toBeFalse()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-3', 'critical'), evaluatorFinding('CVE-2', 'medium')], evaluatorIgnores(), null, null))->toBeFalse()
        ->and($evaluator->isBetter($current, [], evaluatorIgnores('CVE-1'), null, null))->toBeFalse();
});

it('counts a candidate that fixes a finding with an exploit signal as better, also with more relevant findings', function () {
    $evaluator = new CandidateEvaluator;
    $current = [evaluatorFinding('CVE-1', 'high', epss: 0.5, score: 8.1)];

    expect($evaluator->isBetter($current, [evaluatorFinding('CVE-2', 'critical'), evaluatorFinding('CVE-3', 'high')], evaluatorIgnores(), null, null))->toBeTrue()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-2', 'critical'), evaluatorFinding('CVE-3', 'high')], evaluatorIgnores('CVE-1'), null, null))->toBeFalse();
});

it('drives the candidate search with a fixable high known exploited finding', function () {
    $evaluator = new CandidateEvaluator;
    $current = [evaluatorFinding('CVE-1', 'high', kev: true)];

    expect($evaluator->isBetter($current, [], evaluatorIgnores(), null, null))->toBeTrue()
        ->and($evaluator->fixedIds($current, [], evaluatorIgnores()))->toBe(['CVE-1']);
});

it('never counts a candidate that adds a finding with an exploit signal as better, even when it fixes others', function () {
    $evaluator = new CandidateEvaluator;
    $current = [evaluatorFinding('CVE-1', 'critical'), evaluatorFinding('GHSA-9', 'low', kev: true, aliases: ['CVE-9']), evaluatorFinding('CVE-5', 'high', kev: true)];

    expect($evaluator->isBetter($current, [evaluatorFinding('CVE-3', 'low', kev: true)], evaluatorIgnores(), EVALUATOR_EOL, null))->toBeFalse()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-4', 'high', epss: 0.3, score: 7.5)], evaluatorIgnores(), null, null))->toBeFalse()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-4', 'high', epss: 0.3, score: 7.5)], evaluatorIgnores('CVE-4'), null, null))->toBeTrue()
        ->and($evaluator->isBetter($current, [evaluatorFinding('cve-9', 'low', kev: true)], evaluatorIgnores(), null, null))->toBeTrue();
});

it('does not treat a finding of the current image whose EPSS went up as added', function () {
    $evaluator = new CandidateEvaluator;
    $current = [evaluatorFinding('CVE-1', 'critical'), evaluatorFinding('CVE-2', 'high', epss: 0.05, score: 7.5)];

    expect($evaluator->isBetter($current, [evaluatorFinding('CVE-2', 'high', epss: 0.4, score: 7.5)], evaluatorIgnores(), null, null))->toBeTrue();
});

it('counts leaving an end-of-life distro as better and an end-of-life distro as one relevant problem', function () {
    $evaluator = new CandidateEvaluator;
    $none = evaluatorIgnores();

    expect($evaluator->isBetter([], [], $none, EVALUATOR_EOL, null))->toBeTrue()
        ->and($evaluator->isBetter([evaluatorFinding('CVE-1', 'high')], [evaluatorFinding('CVE-1', 'high'), evaluatorFinding('CVE-2', 'critical')], $none, EVALUATOR_EOL, null))->toBeTrue()
        ->and($evaluator->isBetter([], [], $none, EVALUATOR_EOL, EVALUATOR_EOL))->toBeFalse()
        ->and($evaluator->isBetter([evaluatorFinding('CVE-1', 'critical')], [], $none, null, EVALUATOR_EOL))->toBeFalse()
        ->and($evaluator->isBetter([evaluatorFinding('CVE-1', 'critical'), evaluatorFinding('CVE-2', 'high')], [], $none, null, EVALUATOR_EOL))->toBeTrue()
        ->and($evaluator->isBetter([evaluatorFinding('CVE-1', 'critical')], [], $none, EVALUATOR_EOL, EVALUATOR_EOL))->toBeTrue()
        ->and($evaluator->isBetter([], [], $none, null, null))->toBeFalse();
});

it('counts relevant findings by severity, mediums only with an exploit signal', function () {
    $evaluator = new CandidateEvaluator;
    $findings = [
        evaluatorFinding('CVE-1', 'critical'),
        evaluatorFinding('CVE-2', 'high'),
        evaluatorFinding('CVE-3', 'medium'),
        evaluatorFinding('CVE-4', 'medium', kev: true),
        evaluatorFinding('CVE-5', 'low', kev: true),
        evaluatorFinding('CVE-6', 'high'),
    ];

    expect($evaluator->counts($evaluator->relevant($findings, evaluatorIgnores('CVE-6'))))->toBe(['critical' => 1, 'high' => 1, 'medium' => 1]);
});

it('keeps the current findings that another image also has under one of their IDs', function () {
    $evaluator = new CandidateEvaluator;
    $inBase = evaluatorFinding('GHSA-aaaa', aliases: ['CVE-2026-0001']);
    $added = evaluatorFinding('CVE-2099-0001');

    expect($evaluator->shared([$inBase, $added], [evaluatorFinding('cve-2026-0001')]))->toBe([$inBase])
        ->and($evaluator->shared([$inBase, $added], []))->toBe([]);
});
