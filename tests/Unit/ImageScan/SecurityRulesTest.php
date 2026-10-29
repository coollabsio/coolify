<?php

use App\Models\ImageScanIgnore;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\SecurityRules;

function securityFinding(array $overrides = []): Finding
{
    return Finding::fromArray([
        'id' => 'CVE-2024-0001', 'aliases' => [], 'severity' => 'medium', 'package' => 'openssl', 'version' => '1.0',
        'type' => 'apk', 'artifactId' => 'a', 'fixedIn' => '1.1', 'fixState' => 'fixed', 'kev' => false,
        'namespace' => 'alpine:distro:alpine:3.16', ...$overrides,
    ]);
}

function securityIgnores(string ...$ids): IgnoreMatcher
{
    return IgnoreMatcher::fromIgnores(collect(array_map(fn (string $id) => new ImageScanIgnore(['reason' => 'test', 'vulnerability_id' => $id]), $ids)));
}

it('gives a known exploited finding the exploited signal', function () {
    expect(SecurityRules::exploitSignal(securityFinding(['kev' => true, 'severity' => 'low'])))->toBe('exploited');
});

it('gives a likely signal only with an EPSS of 0.10 and a score of 7.0 or more', function (?float $epss, ?float $score, string $severity, ?string $signal) {
    expect(SecurityRules::exploitSignal(securityFinding(['epss' => $epss, 'cvssScore' => $score, 'severity' => $severity])))->toBe($signal);
})->with([
    'epss 0.2 and score 7.5' => [0.2, 7.5, 'high', 'likely'],
    'epss 0.10 and score 7.0' => [0.10, 7.0, 'high', 'likely'],
    'epss 0.2 and score 5.3' => [0.2, 5.3, 'high', null],
    'epss 0.09 and score 9.8' => [0.09, 9.8, 'critical', null],
    'no epss' => [null, 9.8, 'critical', null],
    'epss 0.2, no score, high' => [0.2, null, 'high', 'likely'],
    'epss 0.2, no score, critical' => [0.2, null, 'critical', 'likely'],
    'epss 0.2, no score, medium' => [0.2, null, 'medium', null],
]);

it('gives CVE-2023-2650 of the fixture no exploit signal: a high EPSS but a score of 6.5', function () {
    $findings = (new GrypeReportParser)->parse(file_get_contents(dirname(__DIR__, 2).'/Fixtures/image-scan/grype.ndjson'))['findings'];
    $finding = collect($findings)->firstWhere('id', 'CVE-2023-2650');

    expect($finding->epss)->toBeGreaterThan(0.1)
        ->and(SecurityRules::exploitSignal($finding))->toBeNull()
        ->and(SecurityRules::isRelevant($finding, securityIgnores()))->toBeFalse();
});

it('counts high and critical findings and findings with an exploit signal as relevant', function () {
    $none = securityIgnores();

    expect(SecurityRules::isRelevant(securityFinding(['severity' => 'high']), $none))->toBeTrue()
        ->and(SecurityRules::isRelevant(securityFinding(['severity' => 'critical']), $none))->toBeTrue()
        ->and(SecurityRules::isRelevant(securityFinding(['severity' => 'medium']), $none))->toBeFalse()
        ->and(SecurityRules::isRelevant(securityFinding(['severity' => 'low', 'kev' => true]), $none))->toBeTrue()
        ->and(SecurityRules::isRelevant(securityFinding(['severity' => 'medium', 'epss' => 0.5, 'cvssScore' => 7.2]), $none))->toBeTrue();
});

it('does not count an ignored finding as relevant', function () {
    $finding = securityFinding(['id' => 'GHSA-xxxx', 'aliases' => ['CVE-2024-9999'], 'severity' => 'critical', 'kev' => true]);

    expect(SecurityRules::isRelevant($finding, securityIgnores('cve-2024-9999')))->toBeFalse();
});

it('does not count an OS package matched only by CPE as relevant', function (string $type, string $namespace, bool $cpeOnly) {
    $finding = securityFinding(['severity' => 'critical', 'kev' => true, 'type' => $type, 'namespace' => $namespace]);

    expect(SecurityRules::isCpeOnlyOsMatch($finding))->toBe($cpeOnly)
        ->and(SecurityRules::isRelevant($finding, securityIgnores()))->toBe(! $cpeOnly);
})->with([
    'apk by cpe' => ['apk', 'nvd:cpe', true],
    'deb by cpe' => ['deb', 'nvd:cpe', true],
    'rpm by cpe' => ['rpm', 'NVD:CPE', true],
    'apk by distro' => ['apk', 'alpine:distro:alpine:3.16', false],
    'binary by cpe' => ['binary', 'nvd:cpe', false],
    'npm by cpe' => ['npm', 'nvd:cpe', false],
    'old row without namespace' => ['apk', '', false],
]);

it('lists the IDs and aliases of the relevant findings in lowercase', function () {
    $findings = [
        securityFinding(['id' => 'GHSA-AAAA', 'aliases' => ['CVE-2024-1'], 'severity' => 'high']),
        securityFinding(['id' => 'CVE-2024-2', 'severity' => 'medium']),
        securityFinding(['id' => 'CVE-2024-3', 'severity' => 'critical']),
        securityFinding(['id' => 'CVE-2024-1', 'severity' => 'high', 'package' => 'libssl']),
    ];

    expect(SecurityRules::relevantIds($findings, securityIgnores('CVE-2024-3')))->toBe(['ghsa-aaaa', 'cve-2024-1']);
});
