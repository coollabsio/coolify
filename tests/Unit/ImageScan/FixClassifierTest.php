<?php

use App\Enums\ImageScanAlertLevel;
use App\Enums\ImageScanFixState;
use App\Models\ImageScanIgnore;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\FixClassifier;
use App\Services\ImageScan\GrypeReportParser;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\ScanSummary;

function postgresFindings(): array
{
    return (new GrypeReportParser)->parse(file_get_contents(dirname(__DIR__, 2).'/Fixtures/image-scan/grype.ndjson'))['findings'];
}

function classifierFinding(array $overrides = []): Finding
{
    return Finding::fromArray(array_merge([
        'id' => 'CVE-2024-0001', 'severity' => 'high', 'package' => 'openssl', 'version' => '1.0', 'type' => 'apk',
        'artifactId' => 'abc', 'fixedIn' => '1.1', 'fixState' => 'fixed',
    ], $overrides));
}

function classifyAll(array $findings, bool $buildsImage, bool $isBuilderImage = false, array $candidates = []): array
{
    $classifier = new FixClassifier;
    $counts = [];

    foreach ($findings as $finding) {
        $state = $classifier->classify($finding, $buildsImage, $isBuilderImage, $candidates)->value;
        $counts[$state] = ($counts[$state] ?? 0) + 1;
    }

    return $counts;
}

it('treats a database image with no candidates as having nothing fixable now', function () {
    $counts = classifyAll(postgresFindings(), buildsImage: false);

    expect($counts)->toBe(['not_released' => 88, 'no_fix' => 78]);
});

it('marks a finding fixable now when a candidate image fixes it by id', function () {
    $finding = collect(postgresFindings())->firstWhere('id', 'CVE-2025-1094');

    expect((new FixClassifier)->classify($finding, false, false, ['cve-2025-1094']))->toBe(ImageScanFixState::FixableNow)
        ->and((new FixClassifier)->classify($finding, false, false, ['CVE-2025-0001']))->toBe(ImageScanFixState::NotReleased);
});

it('matches candidate fixed ids through aliases', function () {
    $finding = classifierFinding(['id' => 'GHSA-jfh8-c2jp-5v3q', 'aliases' => ['CVE-2021-44228']]);

    expect((new FixClassifier)->classify($finding, false, false, ['CVE-2021-44228']))->toBe(ImageScanFixState::FixableNow);
});

it('classifies a candidate fix even when the report has no package fix', function () {
    $finding = classifierFinding(['fixedIn' => null, 'fixState' => 'unknown']);

    expect((new FixClassifier)->classify($finding, false, false, ['CVE-2024-0001']))->toBe(ImageScanFixState::FixableNow)
        ->and((new FixClassifier)->classify($finding, false, false, []))->toBe(ImageScanFixState::NoFix);
});

it('makes every finding with a package fix fixable now for an image the user builds', function () {
    $findings = postgresFindings();
    $withFix = count(array_filter($findings, fn (Finding $f) => $f->fixState === 'fixed' && $f->fixedIn !== null));
    $counts = classifyAll($findings, buildsImage: true);

    expect($withFix)->toBe(88)
        ->and($counts['fixable_now'])->toBe($withFix)
        ->and($counts['no_fix'])->toBe(count($findings) - $withFix)
        ->and($counts)->not->toHaveKey('not_released');
});

it('does not count a fixed state without a fixed version as a package fix', function () {
    $finding = classifierFinding(['fixedIn' => null]);

    expect((new FixClassifier)->classify($finding, true, false, []))->toBe(ImageScanFixState::NoFix);
});

it('treats wont-fix and not-fixed as no fix', function () {
    foreach (['wont-fix', 'not-fixed'] as $state) {
        expect((new FixClassifier)->classify(classifierFinding(['fixState' => $state, 'fixedIn' => null]), true, false, []))
            ->toBe(ImageScanFixState::NoFix);
    }
});

it('leaves OS packages of a builder image for a builder update', function () {
    $counts = classifyAll(postgresFindings(), buildsImage: true, isBuilderImage: true);

    expect($counts)->toBe(['fixable_now' => 58, 'not_released' => 30, 'no_fix' => 78]);
});

it('treats rpm and deb packages as OS packages of a builder image', function () {
    foreach (['rpm', 'deb', 'apk'] as $type) {
        expect((new FixClassifier)->classify(classifierFinding(['type' => $type]), true, true, []))
            ->toBe(ImageScanFixState::NotReleased);
    }

    expect((new FixClassifier)->classify(classifierFinding(['type' => 'go-module']), true, true, []))
        ->toBe(ImageScanFixState::FixableNow);
});

it('keeps base layer findings of a Dockerfile build fixable now', function () {
    $finding = classifierFinding(['layer' => 'base']);

    expect((new FixClassifier)->classify($finding, true, false, []))->toBe(ImageScanFixState::FixableNow);
});

function summaryFor(array $findings, bool $buildsImage = true, ?IgnoreMatcher $ignores = null, ImageScanAlertLevel $level = ImageScanAlertLevel::High, ?array $eol = null, bool $truncated = false, array $candidates = []): array
{
    $classifier = new FixClassifier;

    return ScanSummary::build(
        $findings,
        fn (Finding $f) => $classifier->classify($f, $buildsImage, false, $candidates),
        $ignores ?? IgnoreMatcher::fromIgnores(collect()),
        $level,
        $eol,
        $truncated,
    );
}

it('builds counts with every fix state and severity present', function () {
    $summary = summaryFor([]);

    foreach (['fixable_now', 'not_released', 'no_fix'] as $state) {
        expect($summary['counts'][$state])->toBe(['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0]);
    }

    expect($summary)->toMatchArray(['fixable_now_at_level' => 0, 'known_exploited_fixable' => 0, 'ignored' => 0, 'eol_distro' => null, 'truncated' => false]);
});

it('counts findings by fix state and severity and passes eol and truncated through', function () {
    $findings = [
        classifierFinding(['id' => 'A', 'severity' => 'critical']),
        classifierFinding(['id' => 'B', 'severity' => 'medium']),
        classifierFinding(['id' => 'C', 'severity' => 'low', 'fixedIn' => null, 'fixState' => 'not-fixed']),
        classifierFinding(['id' => 'D', 'severity' => 'unknown']),
    ];
    $eol = ['name' => 'alpine', 'version' => '3.16.2'];

    $summary = summaryFor($findings, eol: $eol, truncated: true);

    expect($summary['counts']['fixable_now'])->toBe(['critical' => 1, 'high' => 0, 'medium' => 1, 'low' => 0, 'unknown' => 1])
        ->and($summary['counts']['no_fix']['low'])->toBe(1)
        ->and($summary['fixable_now_at_level'])->toBe(1)
        ->and($summary['eol_distro'])->toBe($eol)
        ->and($summary['truncated'])->toBeTrue();
});

it('counts fixable findings at the alert level', function () {
    $findings = [
        classifierFinding(['id' => 'A', 'severity' => 'critical']),
        classifierFinding(['id' => 'B', 'severity' => 'high']),
        classifierFinding(['id' => 'C', 'severity' => 'medium']),
        classifierFinding(['id' => 'D', 'severity' => 'high', 'fixedIn' => null, 'fixState' => 'unknown']),
    ];

    expect(summaryFor($findings, level: ImageScanAlertLevel::Critical)['fixable_now_at_level'])->toBe(1)
        ->and(summaryFor($findings, level: ImageScanAlertLevel::High)['fixable_now_at_level'])->toBe(2)
        ->and(summaryFor($findings, level: ImageScanAlertLevel::Medium)['fixable_now_at_level'])->toBe(3);
});

it('counts known exploited findings that are fixable now at any severity', function () {
    $findings = [
        classifierFinding(['id' => 'A', 'severity' => 'low', 'kev' => true]),
        classifierFinding(['id' => 'B', 'severity' => 'critical', 'kev' => true, 'fixedIn' => null, 'fixState' => 'unknown']),
        classifierFinding(['id' => 'C', 'severity' => 'critical']),
    ];

    expect(summaryFor($findings)['known_exploited_fixable'])->toBe(1);
});

it('excludes ignored findings from counts and counts them as ignored', function () {
    $findings = [
        classifierFinding(['id' => 'CVE-2024-0001', 'severity' => 'critical', 'kev' => true]),
        classifierFinding(['id' => 'CVE-2024-0002', 'severity' => 'critical']),
        classifierFinding(['id' => 'GHSA-x', 'aliases' => ['CVE-2024-0003'], 'package' => 'zlib', 'severity' => 'high']),
    ];
    $ignores = IgnoreMatcher::fromIgnores(collect([
        new ImageScanIgnore(['reason' => 'test', 'vulnerability_id' => 'CVE-2024-0001']),
        new ImageScanIgnore(['reason' => 'test', 'vulnerability_id' => 'CVE-2024-0003']),
    ]));

    $summary = summaryFor($findings, ignores: $ignores);

    expect($summary['ignored'])->toBe(2)
        ->and($summary['fixable_now_at_level'])->toBe(1)
        ->and($summary['known_exploited_fixable'])->toBe(0)
        ->and($summary['counts']['fixable_now'])->toBe(['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0]);
});

it('summarizes the postgres report as a database with a candidate fix', function () {
    $summary = summaryFor(postgresFindings(), buildsImage: false, candidates: ['CVE-2025-1094']);

    expect(array_sum($summary['counts']['fixable_now']))->toBe(1)
        ->and($summary['counts']['fixable_now']['high'])->toBe(1)
        ->and(array_sum($summary['counts']['not_released']))->toBe(87)
        ->and(array_sum($summary['counts']['no_fix']))->toBe(78);
});
