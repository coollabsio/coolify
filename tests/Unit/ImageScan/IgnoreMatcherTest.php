<?php

use App\Models\ImageScanIgnore;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\IgnoreMatcher;

function ignoreMatcherFinding(array $overrides = []): Finding
{
    return Finding::fromArray(array_merge([
        'id' => 'GHSA-jfh8-c2jp-5v3q',
        'aliases' => ['CVE-2021-44228'],
        'severity' => 'critical',
        'package' => 'log4j-core',
        'version' => '2.14.0',
        'type' => 'java-archive',
        'artifactId' => 'abc',
        'fixState' => 'fixed',
    ], $overrides));
}

function ignoreMatcherFor(array ...$rows): IgnoreMatcher
{
    return IgnoreMatcher::fromIgnores(collect(array_map(
        fn (array $row) => new ImageScanIgnore(['reason' => 'test', ...$row]),
        $rows,
    )));
}

it('matches by the finding id', function () {
    $ignore = ignoreMatcherFor(['vulnerability_id' => 'GHSA-jfh8-c2jp-5v3q'])->matches(ignoreMatcherFinding());

    expect($ignore)->toBeInstanceOf(ImageScanIgnore::class)
        ->and($ignore->vulnerability_id)->toBe('GHSA-jfh8-c2jp-5v3q');
});

it('matches an ignore for a CVE against a GHSA finding through its alias', function () {
    expect(ignoreMatcherFor(['vulnerability_id' => 'CVE-2021-44228'])->matches(ignoreMatcherFinding()))
        ->not->toBeNull();
});

it('compares vulnerability ids case-insensitively', function () {
    expect(ignoreMatcherFor(['vulnerability_id' => 'cve-2021-44228'])->matches(ignoreMatcherFinding()))
        ->not->toBeNull()
        ->and(ignoreMatcherFor(['vulnerability_id' => 'ghsa-JFH8-c2jp-5v3q'])->matches(ignoreMatcherFinding()))
        ->not->toBeNull();
});

it('does not match another vulnerability', function () {
    expect(ignoreMatcherFor(['vulnerability_id' => 'CVE-2024-0001'])->matches(ignoreMatcherFinding()))->toBeNull();
});

it('matches by package name exactly', function () {
    expect(ignoreMatcherFor(['package' => 'log4j-core'])->matches(ignoreMatcherFinding()))->not->toBeNull()
        ->and(ignoreMatcherFor(['package' => 'Log4j-Core'])->matches(ignoreMatcherFinding()))->toBeNull()
        ->and(ignoreMatcherFor(['package' => 'log4j'])->matches(ignoreMatcherFinding()))->toBeNull();
});

it('requires both fields to match when an ignore sets a vulnerability and a package', function () {
    $matcher = ignoreMatcherFor(['vulnerability_id' => 'CVE-2021-44228', 'package' => 'openssl']);

    expect($matcher->matches(ignoreMatcherFinding()))->toBeNull()
        ->and($matcher->matches(ignoreMatcherFinding(['package' => 'openssl'])))->not->toBeNull();

    expect(ignoreMatcherFor(['vulnerability_id' => 'CVE-2021-44228', 'package' => 'log4j-core'])->matches(ignoreMatcherFinding()))
        ->not->toBeNull();
});

it('never matches an ignore with neither a vulnerability nor a package', function () {
    expect(ignoreMatcherFor([])->matches(ignoreMatcherFinding()))->toBeNull();
});

it('matches a team-wide ignore with no resource', function () {
    $ignore = ignoreMatcherFor(['vulnerability_id' => 'CVE-2021-44228', 'resource_type' => null, 'resource_id' => null])
        ->matches(ignoreMatcherFinding());

    expect($ignore)->not->toBeNull();
});

it('matches nothing when there are no ignores', function () {
    expect(IgnoreMatcher::fromIgnores(collect())->matches(ignoreMatcherFinding()))->toBeNull();
});
