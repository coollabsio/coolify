<?php

use App\Enums\ImageScanAlertLevel;
use App\Services\ImageScan\CandidateEvaluator;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;

/**
 * @return list<Finding>
 */
function evaluatorFixture(string $name): array
{
    return (new GrypeReportParser)->parse(file_get_contents(dirname(__DIR__, 2)."/Fixtures/image-scan/{$name}.ndjson"))['findings'];
}

function evaluatorFinding(string $id, string $severity = 'high', bool $kev = false, array $aliases = []): Finding
{
    return Finding::fromArray([
        'id' => $id, 'aliases' => $aliases, 'severity' => $severity, 'package' => 'openssl', 'version' => '1.0', 'type' => 'apk',
        'artifactId' => 'a', 'fixedIn' => '1.1', 'fixState' => 'fixed', 'kev' => $kev,
    ]);
}

it('does not recommend 15.11-alpine: it has more high and critical findings than 15.0-alpine', function () {
    $evaluator = new CandidateEvaluator;
    $current = $evaluator->summarize(evaluatorFixture('grype'), ImageScanAlertLevel::High);

    expect($current['at_level'])->toBe(91)
        ->and($evaluator->isBetter($current, evaluatorFixture('grype-candidate-15.11'), ImageScanAlertLevel::High, true, false))->toBeFalse();
});

it('recommends 15-alpine and lists the fixed IDs with their aliases', function () {
    $evaluator = new CandidateEvaluator;
    $currentFindings = evaluatorFixture('grype');
    $candidate = evaluatorFixture('grype-candidate-15-alpine');
    $current = $evaluator->summarize($currentFindings, ImageScanAlertLevel::High);
    $fixed = $evaluator->fixedIds($evaluator->atLevel($currentFindings, ImageScanAlertLevel::High), $candidate);

    expect($evaluator->isBetter($current, $candidate, ImageScanAlertLevel::High, true, false))->toBeTrue()
        ->and($fixed)->toContain('CVE-2025-1094')
        ->and($evaluator->summarize($candidate, ImageScanAlertLevel::High)['counts'])->toBe(['critical' => 3, 'high' => 32, 'medium' => 0]);
});

it('keeps a finding that the candidate still has under another ID', function () {
    $evaluator = new CandidateEvaluator;
    $current = [evaluatorFinding('GHSA-1111', aliases: ['CVE-2024-1']), evaluatorFinding('CVE-2024-2')];
    $candidate = [evaluatorFinding('CVE-2024-1')];

    expect($evaluator->fixedIds($current, $candidate))->toBe(['CVE-2024-2']);
});

it('counts findings at the alert level in total, not only fixable ones', function () {
    $evaluator = new CandidateEvaluator;
    $current = $evaluator->summarize([evaluatorFinding('CVE-1', 'critical'), evaluatorFinding('CVE-2', 'low')], ImageScanAlertLevel::High);
    $notFixable = Finding::fromArray([...evaluatorFinding('CVE-3', 'high')->toArray(), 'fixState' => 'not-fixed', 'fixedIn' => null]);

    expect($evaluator->isBetter($current, [$notFixable], ImageScanAlertLevel::High, false, false))->toBeFalse()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-4', 'medium')], ImageScanAlertLevel::High, false, false))->toBeTrue();
});

it('rejects a candidate with a new known exploited finding', function () {
    $evaluator = new CandidateEvaluator;
    $current = $evaluator->summarize([evaluatorFinding('CVE-1', 'critical'), evaluatorFinding('CVE-2', 'critical'), evaluatorFinding('CVE-9', 'low', kev: true)], ImageScanAlertLevel::High);

    expect($evaluator->isBetter($current, [evaluatorFinding('CVE-3', 'low', kev: true)], ImageScanAlertLevel::High, false, false))->toBeFalse()
        ->and($evaluator->isBetter($current, [evaluatorFinding('CVE-9', 'low', kev: true)], ImageScanAlertLevel::High, false, false))->toBeTrue();
});

it('rejects an end-of-life candidate only when the current distro is supported', function () {
    $evaluator = new CandidateEvaluator;
    $current = $evaluator->summarize([evaluatorFinding('CVE-1', 'critical')], ImageScanAlertLevel::High);

    expect($evaluator->isBetter($current, [], ImageScanAlertLevel::High, false, true))->toBeFalse()
        ->and($evaluator->isBetter($current, [], ImageScanAlertLevel::High, true, true))->toBeTrue()
        ->and($evaluator->isBetter($current, [], ImageScanAlertLevel::High, false, false))->toBeTrue();
});

it('keeps the current findings that another image also has under one of their IDs', function () {
    $evaluator = new CandidateEvaluator;
    $inBase = evaluatorFinding('GHSA-aaaa', aliases: ['CVE-2026-0001']);
    $added = evaluatorFinding('CVE-2099-0001');

    expect($evaluator->shared([$inBase, $added], [evaluatorFinding('cve-2026-0001')]))->toBe([$inBase])
        ->and($evaluator->shared([$inBase, $added], []))->toBe([]);
});
