<?php

use App\Actions\Server\CheckAutomaticSecurityUpdatesRun;
use App\Models\Server;

afterEach(function () {
    Mockery::close();
});

/**
 * Captured from unattended-upgrades 2.9.1 on Debian 12, with an earlier run above the last one.
 */
const UNATTENDED_UPGRADES_LOG = <<<'LOG'
2026-10-28 03:00:01,100 INFO Starting unattended upgrades script
2026-10-28 03:00:02,100 INFO No packages found that can be upgraded unattended and no pending auto-removals
2026-10-29 17:44:28,205 INFO Checking if system is running on battery is skipped. Please install powermgmt-base package to check power status and skip installing updates when the system is running on battery.
2026-10-29 17:44:28,206 INFO Starting unattended upgrades script
2026-10-29 17:44:28,206 INFO Allowed origins are: origin=Debian,codename=bookworm,label=Debian, origin=Debian,codename=bookworm,label=Debian-Security, origin=Debian,codename=bookworm-security,label=Debian-Security
2026-10-29 17:44:28,206 INFO Initial blacklist:
2026-10-29 17:44:28,206 INFO Initial whitelist (not strict):
2026-10-29 17:44:29,141 INFO Packages that will be upgraded: tzdata libssl3 openssl
2026-10-29 17:44:29,141 INFO Writing dpkg log to /var/log/unattended-upgrades/unattended-upgrades-dpkg.log
2026-10-29 17:44:33,237 INFO All upgrades installed
REBOOT=yes
LOG;

test('reads the updated packages of the last unattended-upgrades run', function () {
    expect(CheckAutomaticSecurityUpdatesRun::parseAptLog(UNATTENDED_UPGRADES_LOG))->toBe([
        'started_at' => '2026-10-29 17:44:28',
        'failed' => false,
        'packages' => ['tzdata', 'libssl3', 'openssl'],
        'errors' => [],
    ]);
});

test('reads a failed unattended-upgrades run', function () {
    $log = <<<'LOG'
2026-10-29 03:00:01,100 INFO Starting unattended upgrades script
2026-10-29 03:00:05,100 INFO Packages that will be upgraded: libssl3
2026-10-29 03:00:09,100 ERROR Installing the upgrades failed!
2026-10-29 03:00:09,101 ERROR error message: installArchives() failed
LOG;

    expect(CheckAutomaticSecurityUpdatesRun::parseAptLog($log))->toBe([
        'started_at' => '2026-10-29 03:00:01',
        'failed' => true,
        'packages' => ['libssl3'],
        'errors' => ['Installing the upgrades failed!', 'error message: installArchives() failed'],
    ]);
});

test('reads a run that found nothing to update', function () {
    $log = <<<'LOG'
2026-10-29 03:00:01,100 INFO Starting unattended upgrades script
2026-10-29 03:00:02,100 INFO No packages found that can be upgraded unattended and no pending auto-removals
LOG;

    expect(CheckAutomaticSecurityUpdatesRun::parseAptLog($log))
        ->toBe(['started_at' => '2026-10-29 03:00:01', 'failed' => false, 'packages' => [], 'errors' => []]);
});

test('ignores an unattended-upgrades run that is still running, and a missing log', function (string $log) {
    expect(CheckAutomaticSecurityUpdatesRun::parseAptLog($log))->toBeNull();
})->with([
    'running' => "2026-10-29 03:00:01,100 INFO Starting unattended upgrades script\n2026-10-29 03:00:05,100 INFO Packages that will be upgraded: libssl3\nREBOOT=no",
    'no log' => 'REBOOT=no',
]);

test('reads the installed packages of the last dnf-automatic run', function () {
    $output = "RUN=Thu 2026-10-29 03:00:00 UTC\nSTATE=inactive\nRESULT=success\nFINISHED=yes\nPKG=openssl-libs\nPKG=kernel-core\nREBOOT=yes";

    expect(CheckAutomaticSecurityUpdatesRun::parseDnfOutput($output))->toBe([
        'started_at' => 'Thu 2026-10-29 03:00:00 UTC',
        'failed' => false,
        'packages' => ['openssl-libs', 'kernel-core'],
        'errors' => [],
    ]);
});

test('reads a failed dnf-automatic run with its journal lines', function () {
    $output = "RUN=Thu 2026-10-29 03:00:00 UTC\nSTATE=failed\nRESULT=exit-code\nFINISHED=yes\nERROR=Error: Failed to download metadata for repo 'baseos'";

    expect(CheckAutomaticSecurityUpdatesRun::parseDnfOutput($output))->toBe([
        'started_at' => 'Thu 2026-10-29 03:00:00 UTC',
        'failed' => true,
        'packages' => [],
        'errors' => ["Error: Failed to download metadata for repo 'baseos'"],
    ]);
});

test('ignores a dnf-automatic run that is still running or never ran', function (string $output) {
    expect(CheckAutomaticSecurityUpdatesRun::parseDnfOutput($output))->toBeNull();
})->with([
    'running' => "RUN=Thu 2026-10-29 03:00:00 UTC\nSTATE=activating\nRESULT=success",
    'never ran' => 'REBOOT=no',
]);

test('runs every last-run command as root for a non-root SSH user', function (string $command) {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->shouldReceive('setAttribute')->andReturnSelf();
    $server->user = 'ubuntu';

    $parsed = parseCommandsByLineForSudo(collect([$command]), $server);

    expect($parsed)->toHaveCount(1)
        ->and(isSingleSudoShellScript($parsed[0]))->toBeTrue()
        ->and(substr_count($parsed[0], 'sudo'))->toBe(1);
})->with([
    'apt' => CheckAutomaticSecurityUpdatesRun::APT_LAST_RUN_COMMAND,
    'dnf' => CheckAutomaticSecurityUpdatesRun::DNF_LAST_RUN_COMMAND,
]);
