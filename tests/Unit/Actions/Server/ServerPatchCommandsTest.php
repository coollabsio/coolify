<?php

use App\Actions\Server\CheckUpdates;
use App\Actions\Server\UpdatePackage;
use App\Models\Server;

function patchCommandsNonRootServer(): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->shouldReceive('setAttribute')->andReturnSelf();
    $server->user = 'ubuntu';

    return $server;
}

afterEach(function () {
    Mockery::close();
});

it('updates apt packages without prompts and keeps changed configuration files', function () {
    expect(UpdatePackage::commands('apt'))->toBe([
        'env DEBIAN_FRONTEND=noninteractive apt-get update -qq',
        'env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade',
    ])->and(UpdatePackage::commands('apt', "'openssl'"))->toBe([
        'env DEBIAN_FRONTEND=noninteractive apt-get update -qq',
        "env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install --only-upgrade 'openssl'",
    ]);
});

it('runs the apt update commands with sudo for a non-root user', function () {
    $parsed = parseCommandsByLineForSudo(collect(UpdatePackage::commands('apt', "'openssl'")), patchCommandsNonRootServer());

    expect($parsed)->toBe([
        'sudo env DEBIAN_FRONTEND=noninteractive apt-get update -qq',
        "sudo env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install --only-upgrade 'openssl'",
    ]);
});

it('allows only a full system upgrade on Arch Linux', function () {
    expect(UpdatePackage::commands('pacman'))->toBe(['pacman -Syu --noconfirm'])
        ->and(UpdatePackage::commands('pacman', "'curl'"))->toBeNull();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('serverStatus')->andReturn(true);

    expect(UpdatePackage::run($server, 'arch', 'curl', 'pacman'))
        ->toHaveKey('error', 'This operating system supports only a full system upgrade. Use "Update all packages".');
});

it('runs the pacman check as one sudo shell script for a non-root user', function () {
    $parsed = parseCommandsByLineForSudo(collect([CheckUpdates::PACMAN_CHECK_COMMAND]), patchCommandsNonRootServer());

    expect($parsed)->toBe(['sudo '.CheckUpdates::PACMAN_CHECK_COMMAND])
        ->and(CheckUpdates::PACMAN_CHECK_COMMAND)->not->toContain('pacman -Sy --noconfirm')
        ->and(CheckUpdates::PACMAN_CHECK_COMMAND)->toContain('--dbpath');
});

it('parses checkupdates output', function () {
    $output = <<<'OUTPUT'
libarchive 3.8.9-1 -> 3.8.10-1
zlib 1:1.3.2-3 -> 1:1.3.2-4
OUTPUT;

    expect((new CheckUpdates)->parsePacmanOutput($output)['updates'])->toBe([
        ['package' => 'libarchive', 'current_version' => '3.8.9-1', 'new_version' => '3.8.10-1', 'architecture' => 'unknown', 'repository' => 'unknown'],
        ['package' => 'zlib', 'current_version' => '1:1.3.2-3', 'new_version' => '1:1.3.2-4', 'architecture' => 'unknown', 'repository' => 'unknown'],
    ]);
});

it('parses dnf4 repoquery output with the installed versions and without the arch suffix', function () {
    // Rocky Linux 9 (dnf 4.14): dnf4 adds its own newline after --qf, so every second line is empty.
    $upgrades = "acl x86_64 2.4.0-1.el9_8 baseos\n\nopenssl x86_64 1:3.5.8-2.el9_8 baseos\n\npython3.11 x86_64 3.11.13-1.el9 appstream\n\n";
    $installed = "acl x86_64 2.3.1-3.el9\nopenssl x86_64 3.0.1-41.el9_0\nbash x86_64 5.1.8-4.el9\n";

    expect((new CheckUpdates)->parseDnfOutput($upgrades, $installed))->toBe([
        'total_updates' => 3,
        'updates' => [
            ['package' => 'acl', 'new_version' => '2.4.0-1.el9_8', 'repository' => 'baseos', 'architecture' => 'x86_64', 'current_version' => '2.3.1-3.el9'],
            ['package' => 'openssl', 'new_version' => '1:3.5.8-2.el9_8', 'repository' => 'baseos', 'architecture' => 'x86_64', 'current_version' => '3.0.1-41.el9_0'],
            ['package' => 'python3.11', 'new_version' => '3.11.13-1.el9', 'repository' => 'appstream', 'architecture' => 'x86_64', 'current_version' => 'unknown'],
        ],
    ]);
});

it('parses dnf5 repoquery output', function () {
    // Fedora 44 (dnf 5.4).
    $upgrades = "ca-certificates noarch 2026.2.90_v9.0.317-1.fc44 updates\ncurl x86_64 8.18.0-14.fc44 updates\n";
    $installed = "curl x86_64 8.18.0-12.fc44\n";

    $updates = (new CheckUpdates)->parseDnfOutput($upgrades, $installed)['updates'];

    expect($updates)->toHaveCount(2)
        ->and($updates[0])->toMatchArray(['package' => 'ca-certificates', 'architecture' => 'noarch', 'current_version' => 'unknown'])
        ->and($updates[1])->toMatchArray(['package' => 'curl', 'new_version' => '8.18.0-14.fc44', 'current_version' => '8.18.0-12.fc44']);
});

it('updates a dnf package by its name', function () {
    expect(UpdatePackage::commands('dnf', "'python3.11'"))->toBe(["dnf upgrade -y 'python3.11'"]);
});

it('marks apt updates from a security suite', function () {
    // Ubuntu 24.04 and Debian 12 (now oldstable-security) `apt list --upgradable` lines.
    $output = <<<'OUTPUT'
Listing... Done
libsystemd0/noble-security 255.4-1ubuntu8.18 amd64 [upgradable from: 255.4-1ubuntu8.17]
openssl/noble-updates,noble-security 3.0.13-0ubuntu3.4 amd64 [upgradable from: 3.0.13-0ubuntu3.1]
perl-base/oldstable-security 5.36.0-7+deb12u4 amd64 [upgradable from: 5.36.0-7+deb12u3]
curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.1]
OUTPUT;
    $action = new CheckUpdates;

    $result = $action->addUpdateDetails($action->parseAptOutput($output), 'apt');

    expect(collect($result['updates'])->pluck('security', 'package')->all())->toBe([
        'libsystemd0' => true,
        'openssl' => true,
        'perl-base' => true,
        'curl' => false,
    ])->and($result['security_updates'])->toBe(3)
        ->and($result['updates'][0]['risk'])->toBe('system');
});

it('moves the apt updates that a phased rollout holds back to their own list', function () {
    // Ubuntu 24.04: cloud-init 26.2 is phased at 80% and this machine is not in the rollout yet.
    $list = <<<'OUTPUT'
Listing...
cloud-init/noble-updates 26.2-0ubuntu1~24.04.2 all [upgradable from: 25.1.4-0ubuntu0~24.04.1]
zlib1g/noble-updates,noble-security 1:1.3.dfsg-3.1ubuntu2.3 amd64 [upgradable from: 1:1.3.dfsg-3.1ubuntu2.2]
OUTPUT;
    $upgrade = <<<'OUTPUT'
Calculating upgrade...
The following upgrades have been deferred due to phasing:
  cloud-init
The following packages will be upgraded:
  zlib1g
1 upgraded, 0 newly installed, 0 to remove and 1 not upgraded.
Inst zlib1g [1:1.3.dfsg-3.1ubuntu2.2] (1:1.3.dfsg-3.1ubuntu2.3 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Conf zlib1g (1:1.3.dfsg-3.1ubuntu2.3 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
OUTPUT;
    $upgradeWithPhased = <<<'OUTPUT'
Calculating upgrade...
The following packages will be upgraded:
  cloud-init zlib1g
2 upgraded, 0 newly installed, 0 to remove and 0 not upgraded.
Inst zlib1g [1:1.3.dfsg-3.1ubuntu2.2] (1:1.3.dfsg-3.1ubuntu2.3 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst cloud-init [25.1.4-0ubuntu0~24.04.1] (26.2-0ubuntu1~24.04.2 Ubuntu:24.04/noble-updates [all])
Conf zlib1g (1:1.3.dfsg-3.1ubuntu2.3 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Conf cloud-init (26.2-0ubuntu1~24.04.2 Ubuntu:24.04/noble-updates [all])
OUTPUT;
    $action = new CheckUpdates;

    $result = $action->separateHeldUpdates($action->parseAptOutput($list), $action->parseAptHeldPackages('', $upgrade, $upgradeWithPhased));

    expect(collect($result['updates'])->pluck('package')->all())->toBe(['zlib1g'])
        ->and($result['total_updates'])->toBe(1)
        ->and($result['held_updates'])->toBe([[
            'package' => 'cloud-init',
            'repository' => 'noble-updates',
            'new_version' => '26.2-0ubuntu1~24.04.2',
            'architecture' => 'all',
            'current_version' => '25.1.4-0ubuntu0~24.04.1',
            'held_reason' => 'phased',
        ]]);

    // A failed simulation must not mark every update as phased.
    expect($action->parseAptHeldPackages('', '', $upgradeWithPhased))->toBe([]);
});

it('moves the apt updates of held packages to the held list', function () {
    // Debian 12 after `apt-mark hold tzdata`: `apt list --upgradable` still shows tzdata.
    $list = <<<'OUTPUT'
Listing...
tzdata/stable-updates 2025b-0+deb12u2 all [upgradable from: 2025b-0+deb12u1]
OUTPUT;
    $action = new CheckUpdates;

    $result = $action->separateHeldUpdates($action->parseAptOutput($list), $action->parseAptHeldPackages("tzdata\n", '', ''));

    expect($result['updates'])->toBe([])
        ->and($result['total_updates'])->toBe(0)
        ->and(collect($result['held_updates'])->pluck('held_reason', 'package')->all())->toBe(['tzdata' => 'held']);
});

it('moves the apk updates that a version pin blocks to the held list', function () {
    // Alpine 3.20.0 with `busybox=1.36.1-r28` in /etc/apk/world: busybox-binsh needs the same busybox version.
    $list = <<<'OUTPUT'
busybox-1.36.1-r31 x86_64 {busybox} (GPL-2.0-only) [upgradable from: busybox-1.36.1-r28]
busybox-binsh-1.36.1-r31 x86_64 {busybox} (GPL-2.0-only) [upgradable from: busybox-binsh-1.36.1-r28]
zlib-1.3.2-r0 x86_64 {zlib} (Zlib) [upgradable from: zlib-1.3.1-r1]
OUTPUT;
    $simulation = <<<'OUTPUT'
WARNING: This simulation is not reliable as apk-tools upgrade is available.
(1/1) Upgrading zlib (1.3.1-r1 -> 1.3.2-r0)
OK: 8 MiB in 14 packages
OUTPUT;
    $action = new CheckUpdates;
    $out = $action->parseApkOutput($list);

    $result = $action->separateHeldUpdates($out, $action->parseApkHeldPackages($out, $simulation));

    expect(collect($result['updates'])->pluck('package')->all())->toBe(['zlib'])
        ->and(collect($result['held_updates'])->pluck('held_reason', 'package')->all())->toBe(['busybox' => 'held', 'busybox-binsh' => 'held'])
        // apk-tools 3 pads the step number; a failed simulation has no OK line and marks nothing.
        ->and($action->parseApkHeldPackages($out, "( 1/3) Upgrading busybox (1.36.1-r28 -> 1.36.1-r31)\n( 2/3) Upgrading busybox-binsh (1.36.1-r28 -> 1.36.1-r31)\n( 3/3) Upgrading zlib (1.3.1-r1 -> 1.3.2-r0)\nOK: 8 MiB in 14 packages\n"))->toBe([])
        ->and($action->parseApkHeldPackages($out, "ERROR: unable to lock database\n"))->toBe([]);
});

it('runs the held package checks with sudo for a non-root user', function () {
    $commands = [CheckUpdates::APT_HELD_COMMAND, CheckUpdates::APT_SIMULATE_UPGRADE_COMMAND, CheckUpdates::APT_SIMULATE_PHASED_UPGRADE_COMMAND, CheckUpdates::APK_SIMULATE_UPGRADE_COMMAND];

    expect(parseCommandsByLineForSudo(collect($commands), patchCommandsNonRootServer()))->toBe([
        'sudo apt-mark showhold 2>/dev/null',
        'sudo LANG=C apt-get -s upgrade 2>/dev/null',
        'sudo LANG=C apt-get -s -o APT::Get::Always-Include-Phased-Updates=true upgrade 2>/dev/null',
        'sudo LANG=C apk upgrade -s 2>/dev/null',
    ]);
});

it('reads the security packages of dnf4 and dnf5 advisories', function () {
    // Rocky Linux 9 (dnf4) and Fedora 42 (dnf5) `dnf updateinfo list --security`.
    $dnf4 = <<<'OUTPUT'
RLSA-2026:42736 Important/Sec. acl-2.4.0-1.el9_8.x86_64
RLSA-2023:0340  Moderate/Sec.  bash-5.1.8-6.el9_1.x86_64
RLSA-2025:23343 Moderate/Sec.  binutils-gold-2.35.2-67.el9_7.1.x86_64
OUTPUT;
    $dnf5 = <<<'OUTPUT'
Name                   Type     Severity                              Package              Issued
FEDORA-2026-11d7d4d8f3 security Moderate     vim-data-2:9.2.390-1.fc42.noarch 2026-05-07 01:26:58
FEDORA-2026-7af660d639 security Moderate   openssl-libs-1:3.2.6-4.fc42.x86_64 2026-05-08 01:24:13
OUTPUT;
    $action = new CheckUpdates;

    expect($action->parseDnfSecurityPackages($dnf4))->toBe(['acl', 'bash', 'binutils-gold'])
        ->and($action->parseDnfSecurityPackages($dnf5))->toBe(['vim-data', 'openssl-libs']);

    $result = $action->addUpdateDetails(['updates' => [
        ['package' => 'openssl-libs', 'repository' => 'updates'],
        ['package' => 'kernel-core', 'repository' => 'updates'],
    ]], 'dnf', $action->parseDnfSecurityPackages($dnf5));

    expect($result['security_updates'])->toBe(1)
        ->and($result['updates'][0]['security'])->toBeTrue()
        ->and($result['updates'][1])->toMatchArray(['security' => false, 'risk' => 'kernel']);
});

it('reads the packages that zypper security patches upgrade', function () {
    $output = <<<'XML'
<?xml version='1.0'?>
<stream>
<message type="info">Loading repository data...</message>
<install-summary download-size="1024" space-usage-diff="0" packages-to-change="3">
<to-install>
<solvable type="patch" kind="patch" name="openSUSE-SLE-15.6-2024-1234" edition="1" arch="noarch"/>
</to-install>
<to-upgrade>
<solvable type="package" kind="package" name="openssh" edition="9.6p1-150600.6.3.1" arch="x86_64" edition-old="9.6p1-150600.6.1"/>
<solvable kind="package" name="libopenssl3" edition="3.1.4-150600.5.7.1" arch="x86_64"/>
</to-upgrade>
</install-summary>
</stream>
XML;

    expect((new CheckUpdates)->parseZypperSecurityPackages($output))->toBe(['openssh', 'libopenssl3'])
        ->and((new CheckUpdates)->parseZypperSecurityPackages('<stream><message>Nothing to do.</message></stream>'))->toBe([]);
});

it('does not report a security count when the package manager cannot tell', function () {
    $result = (new CheckUpdates)->addUpdateDetails(['updates' => [['package' => 'linux', 'repository' => 'unknown']]], 'pacman');

    expect($result['security_updates'])->toBeNull()
        ->and($result['updates'][0])->toMatchArray(['security' => false, 'risk' => 'kernel']);
});

it('classifies risky packages per package manager', function (string $packageManager, string $package, ?string $risk) {
    expect(CheckUpdates::riskFor($packageManager, $package))->toBe($risk);
})->with([
    ['apt', 'docker-ce', 'docker'],
    ['apt', 'containerd.io', 'docker'],
    ['apt', 'linux-image-6.8.0-45-generic', 'kernel'],
    ['apt', 'libc6', 'system'],
    ['apt', 'openssh-server', 'system'],
    ['apt', 'docker-compose', null],
    ['apt', 'linux-firmware', null],
    ['apt', 'curl', null],
    ['dnf', 'kernel-core', 'kernel'],
    ['dnf', 'glibc', 'system'],
    ['dnf', 'moby-engine', 'docker'],
    ['zypper', 'kernel-default', 'kernel'],
    ['pacman', 'linux-lts', 'kernel'],
    ['pacman', 'linux-firmware', null],
    ['apk', 'docker-engine', 'docker'],
    ['apk', 'musl', 'system'],
]);

it('reads the reboot status and runs its check as one sudo shell script', function () {
    $action = new CheckUpdates;

    expect($action->parseRebootStatus("REBOOT=yes\n"))->toBeTrue()
        ->and($action->parseRebootStatus("REBOOT=no\n"))->toBeFalse()
        ->and($action->parseRebootStatus("REBOOT=unknown\n"))->toBeNull()
        ->and($action->parseRebootStatus(''))->toBeNull();

    $server = patchCommandsNonRootServer();
    foreach ([CheckUpdates::REBOOT_CHECK_COMMAND, CheckUpdates::DNF_SECURITY_COMMAND, CheckUpdates::ZYPPER_SECURITY_COMMAND] as $command) {
        expect(parseCommandsByLineForSudo(collect([$command]), $server))->toBe(['sudo '.$command]);
    }
});
