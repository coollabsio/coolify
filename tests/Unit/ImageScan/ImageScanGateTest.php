<?php

use App\Enums\ImageScanAlertLevel;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanOnError;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GateResult;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\ImageScanGate;
use App\Services\ImageScan\ResolvedImageScanPolicy;
use App\Services\ImageScan\ScanSummary;

function gatePolicy(array $overrides = []): ResolvedImageScanPolicy
{
    $values = array_merge([
        'alertLevel' => ImageScanAlertLevel::Critical,
        'gateEnabled' => true,
        'onScanError' => ImageScanOnError::Deploy,
    ], $overrides);

    return new ResolvedImageScanPolicy(
        alertLevel: $values['alertLevel'],
        gateEnabled: $values['gateEnabled'],
        onScanError: $values['onScanError'],
        scanEnabled: true,
        sources: ['alert_level' => 'app', 'gate_enabled' => 'app', 'on_scan_error' => 'app'],
        scanServer: null,
    );
}

function gateFinding(string $id, string $severity = 'critical', array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'severity' => $severity, 'package' => 'openssl', 'version' => '3.0.13', 'type' => 'deb',
        'artifactId' => 'a-'.$id, 'fixedIn' => '3.0.15', 'fixState' => 'fixed', 'kev' => false, 'risk' => 1.0,
    ], $overrides);
}

function gateScan(array $findings, array $attributes = []): ImageScan
{
    $scan = new ImageScan(array_merge([
        'status' => ImageScanStatus::Completed,
        'builds_image' => true,
        'findings' => $findings,
        'candidates' => null,
    ], $attributes));
    $scan->setRelation('resource', new Application(['build_pack' => 'dockerfile']));

    return $scan;
}

function gateIgnores(array ...$rows): IgnoreMatcher
{
    return IgnoreMatcher::fromIgnores(collect(array_map(
        fn (array $row) => new ImageScanIgnore(['reason' => 'test', ...$row]),
        $rows,
    )));
}

function gateSummary(ImageScan $scan, ?ImageScanAlertLevel $level = null, ?IgnoreMatcher $ignores = null): array
{
    return ScanSummary::build(
        array_map(fn (array $row) => Finding::fromArray($row), $scan->findings),
        ImageScanGate::classifierFor($scan),
        $ignores ?? gateIgnores(),
        $level ?? ImageScanAlertLevel::Critical,
    );
}

it('never blocks when the gate is off', function () {
    $scan = gateScan([gateFinding('CVE-1'), gateFinding('CVE-2')]);

    $result = (new ImageScanGate)->evaluate($scan, gatePolicy(['gateEnabled' => false]), gateIgnores());

    expect($result->blocked)->toBeFalse()
        ->and($result->scanRan)->toBeTrue()
        ->and($result->headline)->toBe('');
});

it('reports that no scan ran when the gate is off and there is no scan', function () {
    $result = (new ImageScanGate)->evaluate(null, gatePolicy(['gateEnabled' => false, 'onScanError' => ImageScanOnError::Block]), gateIgnores());

    expect($result->blocked)->toBeFalse()->and($result->scanRan)->toBeFalse();
});

it('blocks on fixable critical findings with the exact headline and known exploited first', function () {
    $scan = gateScan([
        gateFinding('CVE-2026-2345', 'critical', ['risk' => 9.0]),
        gateFinding('CVE-2026-1234', 'critical', ['kev' => true, 'risk' => 1.0]),
        gateFinding('CVE-2026-9999', 'low'),
    ]);

    $result = (new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores());

    expect($result->blocked)->toBeTrue()
        ->and($result->scanRan)->toBeTrue()
        ->and($result->error)->toBeNull()
        ->and($result->count)->toBe(2)
        ->and($result->headline)->toBe('Deploy blocked: 2 critical vulnerabilities with a fix.')
        ->and(array_column($result->top, 'id'))->toBe(['CVE-2026-1234', 'CVE-2026-2345'])
        ->and($result->top[0])->toBe([
            'id' => 'CVE-2026-1234', 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13', 'fixed_in' => '3.0.15', 'kev' => true,
        ]);
});

it('uses the singular for one vulnerability', function () {
    $result = (new ImageScanGate)->evaluate(gateScan([gateFinding('CVE-1')]), gatePolicy(), gateIgnores());

    expect($result->headline)->toBe('Deploy blocked: 1 critical vulnerability with a fix.');
});

it('joins severity parts with and', function () {
    $scan = gateScan([gateFinding('CVE-1'), gateFinding('CVE-2'), gateFinding('CVE-3', 'high')]);

    $result = (new ImageScanGate)->evaluate($scan, gatePolicy(['alertLevel' => ImageScanAlertLevel::High]), gateIgnores());

    expect($result->headline)->toBe('Deploy blocked: 2 critical and 1 high vulnerabilities with a fix.');

    $scan = gateScan([gateFinding('CVE-1'), gateFinding('CVE-2', 'high'), gateFinding('CVE-3', 'medium')]);
    $result = (new ImageScanGate)->evaluate($scan, gatePolicy(['alertLevel' => ImageScanAlertLevel::Medium]), gateIgnores());

    expect($result->headline)->toBe('Deploy blocked: 1 critical, 1 high and 1 medium vulnerabilities with a fix.');
});

it('orders the top list by known exploited, severity, then risk and caps it at five', function () {
    $scan = gateScan([
        gateFinding('H-1', 'high', ['risk' => 99.0]),
        gateFinding('C-LOW', 'critical', ['risk' => 1.0]),
        gateFinding('C-HIGH', 'critical', ['risk' => 8.0]),
        gateFinding('H-KEV', 'high', ['kev' => true, 'risk' => 0.5]),
        gateFinding('M-1', 'medium', ['risk' => 50.0]),
        gateFinding('C-NULL', 'critical', ['risk' => null]),
        gateFinding('C-2', 'critical', ['risk' => 2.0]),
    ]);

    $result = (new ImageScanGate)->evaluate($scan, gatePolicy(['alertLevel' => ImageScanAlertLevel::Medium]), gateIgnores());

    expect($result->count)->toBe(7)
        ->and(array_column($result->top, 'id'))->toBe(['H-KEV', 'C-HIGH', 'C-2', 'C-LOW', 'C-NULL']);
});

it('does not block when the findings are ignored', function () {
    $scan = gateScan([gateFinding('CVE-1'), gateFinding('CVE-2')]);

    $result = (new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores(['vulnerability_id' => 'CVE-1'], ['package' => 'openssl']));

    expect($result->blocked)->toBeFalse()->and($result->count)->toBe(0)->and($result->headline)->toBe('');
});

it('respects the alert level', function () {
    $scan = gateScan([gateFinding('CVE-1', 'high')]);

    expect((new ImageScanGate)->evaluate($scan, gatePolicy(['alertLevel' => ImageScanAlertLevel::Critical]), gateIgnores())->blocked)->toBeFalse()
        ->and((new ImageScanGate)->evaluate($scan, gatePolicy(['alertLevel' => ImageScanAlertLevel::High]), gateIgnores())->blocked)->toBeTrue();
});

it('does not block on findings without a fix', function () {
    $scan = gateScan([gateFinding('CVE-1', 'critical', ['fixedIn' => null, 'fixState' => 'not-fixed'])]);

    expect((new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores())->blocked)->toBeFalse();
});

it('treats base layer fixes of builder images as not fixable now', function () {
    $scan = gateScan([gateFinding('CVE-1')]);
    $scan->setRelation('resource', new Application(['build_pack' => 'nixpacks']));

    expect((new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores())->blocked)->toBeFalse();

    $scan->setRelation('resource', new Application(['build_pack' => 'static']));
    expect((new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores())->blocked)->toBeFalse();

    $scan->setRelation('resource', new Application(['build_pack' => 'railpack']));
    expect((new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores())->blocked)->toBeFalse();

    $scan->setRelation('resource', new Application(['build_pack' => 'dockerfile']));
    expect((new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores())->blocked)->toBeTrue();
});

it('uses candidate images for images the team does not build', function () {
    $scan = gateScan([gateFinding('CVE-1'), gateFinding('CVE-2')], [
        'builds_image' => false,
        'candidates' => [
            ['tag' => '1.2', 'better' => true, 'fixed_ids' => ['CVE-1']],
            ['tag' => '1.1', 'better' => false, 'fixed_ids' => ['CVE-2']],
        ],
    ]);

    $result = (new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores());

    expect($result->blocked)->toBeTrue()
        ->and($result->count)->toBe(1)
        ->and(array_column($result->top, 'id'))->toBe(['CVE-1']);

    $scan->candidates = null;
    expect((new ImageScanGate)->evaluate($scan, gatePolicy(), gateIgnores())->blocked)->toBeFalse();
});

it('does not block on a scan error when the policy is to deploy', function () {
    $result = (new ImageScanGate)->evaluate(null, gatePolicy(), gateIgnores(), ImageScanErrorCode::Timeout);

    expect($result->blocked)->toBeFalse()
        ->and($result->scanRan)->toBeFalse()
        ->and($result->error)->toBe(ImageScanErrorCode::Timeout)
        ->and($result->headline)->toBe('Scan skipped: The scan took too long and was stopped.');
});

it('blocks on a scan error when the policy is to block', function () {
    $result = (new ImageScanGate)->evaluate(null, gatePolicy(['onScanError' => ImageScanOnError::Block]), gateIgnores(), ImageScanErrorCode::DiskSpace, ['server' => 'prod', 'n' => 3]);

    expect($result->blocked)->toBeTrue()
        ->and($result->scanRan)->toBeFalse()
        ->and($result->count)->toBe(0)
        ->and($result->headline)->toBe('Deploy blocked: Not enough disk space on prod: 3 GB needed.');
});

it('treats a missing or unfinished scan as an unknown error', function () {
    $block = gatePolicy(['onScanError' => ImageScanOnError::Block]);

    $result = (new ImageScanGate)->evaluate(null, $block, gateIgnores());
    expect($result->blocked)->toBeTrue()->and($result->error)->toBe(ImageScanErrorCode::Unknown);

    $result = (new ImageScanGate)->evaluate(gateScan([gateFinding('CVE-1')], ['status' => ImageScanStatus::Failed]), gatePolicy(), gateIgnores());
    expect($result->blocked)->toBeFalse()
        ->and($result->scanRan)->toBeFalse()
        ->and($result->error)->toBe(ImageScanErrorCode::Unknown)
        ->and($result->headline)->toBe('Scan skipped: The scan could not run.');
});

it('exports the result as an array', function () {
    $result = (new ImageScanGate)->evaluate(gateScan([gateFinding('CVE-1')]), gatePolicy(), gateIgnores());

    expect($result)->toBeInstanceOf(GateResult::class)
        ->and($result->toArray())->toBe([
            'blocked' => true,
            'scan_ran' => true,
            'error' => null,
            'count' => 1,
            'headline' => 'Deploy blocked: 1 critical vulnerability with a fix.',
            'top' => $result->top,
        ]);
});

it('writes the deploy log lines', function () {
    $scan = gateScan([
        gateFinding('CVE-2026-1234', 'critical', ['kev' => true]),
        gateFinding('CVE-2026-2345', 'critical'),
        gateFinding('CVE-2026-3456', 'high'),
        gateFinding('CVE-N-1', 'low', ['fixedIn' => null, 'fixState' => 'not-fixed']),
        gateFinding('CVE-N-2', 'low', ['fixedIn' => null, 'fixState' => 'not-fixed']),
        gateFinding('CVE-N-3', 'medium', ['fixedIn' => null, 'fixState' => 'not-fixed']),
        gateFinding('CVE-N-4', 'medium', ['fixedIn' => null, 'fixState' => 'not-fixed']),
    ]);
    $policy = gatePolicy();
    $gate = new ImageScanGate;
    $result = $gate->evaluate($scan, $policy, gateIgnores());

    expect($gate->logLines($result, gateSummary($scan)))->toBe([
        'Image scan: 2 critical with a fix, 1 high with a fix, 4 without a fix.',
        'Deploy blocked: 2 critical vulnerabilities with a fix.',
        '  CVE-2026-1234  critical  openssl 3.0.13 → 3.0.15  known exploited',
        '  CVE-2026-2345  critical  openssl 3.0.13 → 3.0.15',
        'See the Vulnerabilities page for all results.',
    ]);
});

it('writes a short log when nothing is fixable and nothing is blocked', function () {
    $scan = gateScan([gateFinding('CVE-N-1', 'low', ['fixedIn' => null, 'fixState' => 'not-fixed'])]);
    $gate = new ImageScanGate;
    $result = $gate->evaluate($scan, gatePolicy(), gateIgnores());

    expect($gate->logLines($result, gateSummary($scan)))->toBe([
        'Image scan: nothing to fix now, 1 without a fix.',
    ]);
});

it('writes the fixable counts without a trailing part when everything has a fix', function () {
    $scan = gateScan([gateFinding('CVE-1', 'high')]);
    $gate = new ImageScanGate;
    $result = $gate->evaluate($scan, gatePolicy(), gateIgnores());

    expect($gate->logLines($result, gateSummary($scan)))->toBe(['Image scan: 1 high with a fix.']);
});

it('writes a skipped line for a scan error', function () {
    $gate = new ImageScanGate;
    $result = $gate->evaluate(null, gatePolicy(), gateIgnores(), ImageScanErrorCode::Timeout);

    expect($gate->logLines($result, []))->toBe(['Scan skipped: The scan took too long and was stopped.']);
});

it('writes a fixed in a newer image line when there is no package fix version', function () {
    $scan = gateScan([gateFinding('CVE-1', 'critical', ['fixedIn' => null, 'fixState' => 'not-fixed', 'kev' => true]), gateFinding('CVE-2')], [
        'builds_image' => false,
        'candidates' => [['tag' => '1.2', 'better' => true, 'fixed_ids' => ['CVE-1', 'CVE-2']]],
    ]);
    $gate = new ImageScanGate;
    $result = $gate->evaluate($scan, gatePolicy(), gateIgnores());

    expect($gate->logLines($result, gateSummary($scan)))->toContain(
        '  CVE-1  critical  openssl 3.0.13  fixed in a newer image  known exploited',
        '  CVE-2  critical  openssl 3.0.13 → 3.0.15',
    );
});

it('keeps the summary fixable count and the gate count equal', function (string $buildPack, bool $buildsImage, ?array $candidates, array $ignoreRows) {
    $scan = gateScan([
        gateFinding('CVE-C1', 'critical'),
        gateFinding('CVE-C2', 'critical', ['type' => 'java-archive']),
        gateFinding('CVE-C3', 'critical', ['fixedIn' => null, 'fixState' => 'not-fixed']),
        gateFinding('CVE-H1', 'high'),
        gateFinding('CVE-H2', 'high', ['type' => 'npm', 'aliases' => ['GHSA-h2']]),
        gateFinding('CVE-M1', 'medium'),
        gateFinding('CVE-M2', 'medium', ['type' => 'apk']),
        gateFinding('CVE-L1', 'low'),
        gateFinding('CVE-U1', 'unknown'),
    ], ['builds_image' => $buildsImage, 'candidates' => $candidates]);
    $scan->setRelation('resource', new Application(['build_pack' => $buildPack]));
    $ignores = gateIgnores(...$ignoreRows);

    foreach (ImageScanAlertLevel::cases() as $level) {
        $policy = gatePolicy(['alertLevel' => $level]);
        $summary = ScanSummary::build(
            array_map(fn (array $row) => Finding::fromArray($row), $scan->findings),
            ImageScanGate::classifierFor($scan),
            $ignores,
            $level,
        );
        $result = (new ImageScanGate)->evaluate($scan, $policy, $ignores);

        expect($result->count)->toBe($summary['fixable_now_at_level'])
            ->and($result->blocked)->toBe($summary['fixable_now_at_level'] > 0);
    }
})->with([
    'dockerfile' => ['dockerfile', true, null, []],
    'dockerfile with ignores' => ['dockerfile', true, null, [['vulnerability_id' => 'CVE-C1'], ['vulnerability_id' => 'GHSA-h2'], ['package' => 'nothing']]],
    'nixpacks builder image' => ['nixpacks', true, null, []],
    'static builder image with ignore' => ['static', true, null, [['vulnerability_id' => 'CVE-C2']]],
    'candidates fix some' => ['dockerfile', false, [
        ['better' => true, 'fixed_ids' => ['CVE-C1', 'GHSA-h2', 'CVE-M1', 'CVE-L1']],
        ['better' => false, 'fixed_ids' => ['CVE-C2', 'CVE-H1']],
    ], []],
    'candidates with ignores' => ['dockerfile', false, [['better' => true, 'fixed_ids' => ['CVE-C1', 'CVE-C2', 'CVE-H1']]], [['vulnerability_id' => 'CVE-C2']]],
    'no candidates' => ['dockerfile', false, null, []],
]);
