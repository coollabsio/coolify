<?php

use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanOnError;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\SecurityGate;

function securityGateFinding(string $id, array $overrides = []): array
{
    return [
        'id' => $id, 'aliases' => [], 'severity' => 'high', 'package' => 'openssl', 'version' => '3.0.13', 'type' => 'deb',
        'artifactId' => 'a-'.$id, 'fixedIn' => null, 'fixState' => 'not-fixed', 'kev' => false, 'epss' => null,
        'cvssScore' => 8.1, 'namespace' => 'debian:distro:debian:12', ...$overrides,
    ];
}

function securityGateExploited(string $id, array $overrides = []): array
{
    return securityGateFinding($id, ['kev' => true, ...$overrides]);
}

function securityGateScan(array $findings, array $attributes = []): ImageScan
{
    $scan = new ImageScan([
        'status' => ImageScanStatus::Completed,
        'builds_image' => true,
        'findings' => $findings,
        'summary' => [],
        ...$attributes,
    ]);
    $scan->setRelation('resource', new Application(['build_pack' => 'dockerfile']));

    return $scan;
}

function securityGateIgnores(array ...$rows): IgnoreMatcher
{
    return IgnoreMatcher::fromIgnores(collect(array_map(fn (array $row) => new ImageScanIgnore(['reason' => 'test', ...$row]), $rows)));
}

describe('mode on', function () {
    it('blocks a new finding that attackers are using', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateExploited('CVE-2026-1')]),
            securityGateScan([]),
            'on',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeTrue()
            ->and($result['error'])->toBeNull()
            ->and($result['headline'])->toBe('Deploy blocked: this image has a security problem that attackers are using.')
            ->and(array_column($result['new'], 'id'))->toBe(['CVE-2026-1'])
            ->and($result['carried'])->toBe([]);
    });

    it('passes a finding that the running image has too and lists it as a warning', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateExploited('CVE-2026-1', ['cvssScore' => 9.8, 'severity' => 'critical'])]),
            securityGateScan([securityGateExploited('CVE-2026-1')]),
            'on',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeFalse()
            ->and($result['headline'])->toBe('')
            ->and(array_column($result['carried'], 'id'))->toBe(['CVE-2026-1'])
            ->and(SecurityGate::logLines($result))->toBe([
                'Image scan: passed.',
                'Already in the running image: a security problem that attackers are using.',
                '  CVE-2026-1  9.8 Critical  openssl 3.0.13  already running',
            ]);
    });

    it('matches a carried over finding by its aliases', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateExploited('GHSA-aaaa-bbbb-cccc', ['aliases' => ['CVE-2026-7']])]),
            securityGateScan([securityGateFinding('CVE-2026-7', ['severity' => 'low'])]),
            'on',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeFalse()->and($result['carried'])->toHaveCount(1);
    });

    it('treats every finding as new on the first deploy', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateExploited('CVE-2026-1'), securityGateFinding('CVE-2026-2', ['epss' => 0.4])]),
            null,
            'on',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeTrue()
            ->and($result['headline'])->toBe('Deploy blocked: this image has 2 security problems that attackers are using.')
            ->and(array_column($result['new'], 'id'))->toBe(['CVE-2026-1', 'CVE-2026-2']);
    });

    it('does not block when the EPSS of a finding that already runs went up', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateFinding('CVE-2026-1', ['epss' => 0.35])]),
            securityGateScan([securityGateFinding('CVE-2026-1', ['epss' => 0.04])]),
            'on',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeFalse()
            ->and(array_column($result['carried'], 'signal'))->toBe(['likely'])
            ->and(SecurityGate::logLines($result)[1])->toBe('Already in the running image: a security problem that attackers are likely to use.');
    });

    it('says likely to use when no blocking finding is exploited', function () {
        $result = SecurityGate::evaluate(securityGateScan([securityGateFinding('CVE-2026-1', ['epss' => 0.2])]), null, 'on', securityGateIgnores());

        expect($result['headline'])->toBe('Deploy blocked: this image has a security problem that attackers are likely to use.');
    });

    it('does not block on critical findings with a fix and no exploit signal', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateFinding('CVE-2026-1', ['severity' => 'critical', 'fixState' => 'fixed', 'fixedIn' => '3.0.15'])], ['summary' => ['eol_distro' => ['name' => 'debian', 'version' => '9']]]),
            null,
            'on',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeFalse()->and($result['new'])->toBe([]);
    });

    it('skips ignored findings and CPE-only OS matches', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([
                securityGateExploited('CVE-2026-1'),
                securityGateExploited('CVE-2026-2', ['namespace' => 'nvd:cpe']),
            ]),
            null,
            'on',
            securityGateIgnores(['vulnerability_id' => 'CVE-2026-1']),
        );

        expect($result['blocked'])->toBeFalse();
    });
});

describe('mode strict', function () {
    it('blocks a critical finding with a package fix', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateFinding('CVE-2026-1', ['severity' => 'critical', 'fixState' => 'fixed', 'fixedIn' => '3.0.15'])]),
            null,
            'strict',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeTrue()
            ->and($result['headline'])->toBe('Deploy blocked: this image has a security problem that needs a security update.')
            ->and(SecurityGate::logLines($result))->toBe([
                'Deploy blocked: this image has a security problem that needs a security update.',
                '  CVE-2026-1  8.1 Critical  openssl 3.0.13  fixed in 3.0.15',
                'See the Security page for all problems.',
            ]);
    });

    it('blocks an end-of-life OS', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([], ['summary' => ['eol_distro' => ['name' => 'debian', 'version' => '9']]]),
            null,
            'strict',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeTrue()
            ->and($result['headline'])->toBe('Deploy blocked: this image has a security problem that needs a security update.')
            ->and(SecurityGate::logLines($result))->toContain('  This image uses Debian 9, which no longer gets security fixes.')
            ->and(SecurityGate::stored($result)['count'])->toBe(1);
    });

    it('uses the plural for several problems that need an update', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateFinding('CVE-2026-1', ['fixState' => 'fixed', 'fixedIn' => '1.1'])], ['summary' => ['eol_distro' => ['name' => 'alpine', 'version' => '3.16']]]),
            null,
            'strict',
            securityGateIgnores(),
        );

        expect($result['headline'])->toBe('Deploy blocked: this image has 2 security problems that need a security update.');
    });

    it('blocks an exploited finding that the running image has too', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([securityGateExploited('CVE-2026-1')]),
            securityGateScan([securityGateExploited('CVE-2026-1')]),
            'strict',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeTrue()
            ->and($result['headline'])->toBe('Deploy blocked: this image has a security problem that attackers are using.')
            ->and($result['carried'])->toBe([]);
    });

    it('does not block on medium findings or findings without a package fix', function () {
        $result = SecurityGate::evaluate(
            securityGateScan([
                securityGateFinding('CVE-2026-1', ['severity' => 'medium', 'cvssScore' => 5.0, 'fixState' => 'fixed', 'fixedIn' => '1.1']),
                securityGateFinding('CVE-2026-2', ['severity' => 'critical']),
            ]),
            null,
            'strict',
            securityGateIgnores(),
        );

        expect($result['blocked'])->toBeFalse();
    });
});

it('never blocks when the mode is off', function (?ImageScanErrorCode $error) {
    $result = SecurityGate::evaluate(
        securityGateScan([securityGateExploited('CVE-2026-1')], ['summary' => ['eol_distro' => ['name' => 'debian', 'version' => '9']]]),
        null,
        'off',
        securityGateIgnores(),
        $error,
        onScanError: ImageScanOnError::Block,
    );

    expect($result['blocked'])->toBeFalse()->and($result['headline'])->toBe('');
})->with([
    'scan ran' => [null],
    'scan error' => [ImageScanErrorCode::Timeout],
]);

it('follows "If the scan cannot run" for a scan error', function (ImageScanOnError $onScanError, bool $blocked, string $headline) {
    $result = SecurityGate::evaluate(null, null, 'on', securityGateIgnores(), ImageScanErrorCode::DiskSpace, ['server' => 'prod', 'n' => 3], $onScanError);

    expect($result['blocked'])->toBe($blocked)
        ->and($result['scan_ran'])->toBeFalse()
        ->and($result['error'])->toBe('disk_space')
        ->and($result['headline'])->toBe($headline)
        ->and(SecurityGate::logLines($result))->toBe([$headline]);
})->with([
    'deploy anyway' => [ImageScanOnError::Deploy, false, 'Scan skipped: Not enough disk space on prod: 3 GB needed.'],
    'block' => [ImageScanOnError::Block, true, 'Deploy blocked: Not enough disk space on prod: 3 GB needed.'],
]);

it('treats a failed scan as an unknown error', function () {
    $result = SecurityGate::evaluate(securityGateScan([], ['status' => ImageScanStatus::Failed]), null, 'strict', securityGateIgnores());

    expect($result['blocked'])->toBeFalse()
        ->and($result['error'])->toBe('unknown')
        ->and($result['headline'])->toBe('Scan skipped: The scan could not run.');
});

it('orders the blocking problems exploited first and caps the log at five', function () {
    $result = SecurityGate::evaluate(securityGateScan([
        securityGateFinding('LIKELY-1', ['epss' => 0.5, 'cvssScore' => 9.9]),
        securityGateExploited('KEV-LOW', ['cvssScore' => 7.0]),
        securityGateExploited('KEV-HIGH', ['cvssScore' => 9.0]),
        securityGateFinding('LIKELY-2', ['epss' => 0.5, 'cvssScore' => 7.5]),
        securityGateFinding('LIKELY-3', ['epss' => 0.5, 'cvssScore' => 7.4]),
        securityGateFinding('LIKELY-4', ['epss' => 0.5, 'cvssScore' => 7.3]),
    ]), null, 'on', securityGateIgnores());

    $lines = SecurityGate::logLines($result);
    $stored = SecurityGate::stored($result);

    expect(array_column($result['new'], 'id'))->toBe(['KEV-HIGH', 'KEV-LOW', 'LIKELY-1', 'LIKELY-2', 'LIKELY-3', 'LIKELY-4'])
        ->and($lines[1])->toBe('  KEV-HIGH  9.0 High  openssl 3.0.13  used by attackers')
        ->and($lines[3])->toBe('  LIKELY-1  9.9 High  openssl 3.0.13  likely to be exploited')
        ->and($lines[6])->toBe('  and 1 more')
        ->and($stored['count'])->toBe(6)
        ->and($stored['top'])->toHaveCount(5)
        ->and($stored)->toMatchArray(['blocked' => true, 'scan_ran' => true, 'error' => null, 'mode' => 'on']);
});

it('leaves out the score of a finding without one', function () {
    $result = SecurityGate::evaluate(securityGateScan([securityGateExploited('CVE-2026-1', ['cvssScore' => null])]), null, 'on', securityGateIgnores());

    expect(SecurityGate::logLines($result)[1])->toBe('  CVE-2026-1  High  openssl 3.0.13  used by attackers');
});

it('counts a finding once when it appears for several packages', function () {
    $result = SecurityGate::evaluate(securityGateScan([
        securityGateExploited('CVE-2026-1'),
        securityGateExploited('CVE-2026-1', ['package' => 'libssl3']),
    ]), null, 'on', securityGateIgnores());

    expect($result['new'])->toHaveCount(1)
        ->and($result['headline'])->toBe('Deploy blocked: this image has a security problem that attackers are using.');
});

it('reads candidate entries from the wrapped shape and from a bare list', function (array $candidates) {
    $scan = securityGateScan([], ['builds_image' => false, 'candidates' => $candidates]);

    expect(array_column($scan->candidateEntries(), 'ref'))->toBe(['postgres:15-alpine', 'postgres:16-alpine']);
})->with([
    'wrapped' => [['status' => 'ok', 'checked_at' => '2026-10-26T00:00:00+00:00', 'entries' => [
        ['ref' => 'postgres:15-alpine', 'kind' => 'line', 'better' => true, 'fixed_ids' => ['CVE-1']],
        ['ref' => 'postgres:16-alpine', 'kind' => 'major', 'better' => false, 'fixed_ids' => ['CVE-2']],
    ]]],
    'bare list' => [[
        ['ref' => 'postgres:15-alpine', 'kind' => 'line', 'better' => true, 'fixed_ids' => ['CVE-1']],
        ['ref' => 'postgres:16-alpine', 'kind' => 'major', 'better' => false, 'fixed_ids' => ['CVE-2']],
    ]],
]);

it('reads no candidate entries of a wrapped result without entries', function () {
    $scan = securityGateScan([], ['candidates' => ['status' => 'auth_required', 'checked_at' => null, 'entries' => []]]);

    expect($scan->candidateEntries())->toBe([])->and($scan->candidateStatus())->toBe('auth_required');
});

it('writes a headline without counts for chat and webhook channels', function (array $findings, array $summary, string $mode, string $counted, string $plain) {
    $result = SecurityGate::evaluate(securityGateScan($findings, ['summary' => $summary]), null, $mode, securityGateIgnores());

    expect($result['headline'])->toBe($counted)
        ->and($result['headline_plain'])->toBe($plain)
        ->and(SecurityGate::stored($result)['headline_plain'])->toBe($plain)
        ->and($plain)->not->toMatch('/\d/');
})->with([
    'exploited' => [[securityGateExploited('CVE-2026-1'), securityGateExploited('CVE-2026-2')], [], 'on',
        'Deploy blocked: this image has 2 security problems that attackers are using.',
        'Deploy blocked: this image has security problems that attackers are using.'],
    'likely' => [[securityGateFinding('CVE-2026-1', ['epss' => 0.3])], [], 'on',
        'Deploy blocked: this image has a security problem that attackers are likely to use.',
        'Deploy blocked: this image has security problems that attackers are likely to use.'],
    'update' => [[securityGateFinding('CVE-2026-1', ['fixState' => 'fixed', 'fixedIn' => '1.1'])], ['eol_distro' => ['name' => 'debian', 'version' => '9']], 'strict',
        'Deploy blocked: this image has 2 security problems that need a security update.',
        'Deploy blocked: this image needs a security update.'],
]);
