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
