<?php

use App\Actions\Server\InstallSysbox;
use App\Models\Server;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

describe('Docker network settings', function () {
    it('adds the current bridge address and the Docker default pools, and keeps other settings', function () {
        $config = InstallSysbox::daemonConfigWithNetworkSettings(
            '{"log-driver": "json-file", "log-opts": {"max-size": "10m"}, "features": {}}',
            '172.17.0.1 172.17.0.0/16',
        );
        $decoded = json_decode($config, true);

        expect($decoded['bip'])->toBe('172.17.0.1/16')
            ->and($decoded['log-opts'])->toBe(['max-size' => '10m'])
            ->and($decoded['default-address-pools'][0])->toBe(['base' => '172.17.0.0/16', 'size' => 16])
            ->and(end($decoded['default-address-pools']))->toBe(['base' => '192.168.0.0/16', 'size' => 20])
            ->and($config)->toContain('"features": {}')
            // Sysbox detects both keys with a regex that needs one indented key per line.
            ->toMatch('/^[ ]+"bip": "[0-9.]+.*"/m')
            ->toMatch('/^[ ]+"default-address-pools"/m');
    });

    it('keeps existing address pools and only adds the bridge address', function () {
        $config = InstallSysbox::daemonConfigWithNetworkSettings(
            '{"default-address-pools": [{"base": "10.0.0.0/8", "size": 24}]}',
            '10.0.0.1 10.0.0.0/24',
        );

        expect(json_decode($config, true))->toBe([
            'default-address-pools' => [['base' => '10.0.0.0/8', 'size' => 24]],
            'bip' => '10.0.0.1/24',
        ]);
    });

    it('does not change a configuration that already has both settings', function () {
        expect(InstallSysbox::daemonConfigWithNetworkSettings(
            '{"bip": "172.17.0.1/16", "default-address-pools": [{"base": "172.17.0.0/12", "size": 16}]}',
            '172.17.0.1 172.17.0.0/16',
        ))->toBeNull();
    });

    it('creates the configuration when the file does not exist', function () {
        expect(json_decode(InstallSysbox::daemonConfigWithNetworkSettings('', '172.17.0.1 172.17.0.0/16'), true))
            ->toHaveKeys(['bip', 'default-address-pools']);
    });

    it('refuses an invalid configuration or bridge address', function (string $config, string $bridge) {
        InstallSysbox::daemonConfigWithNetworkSettings($config, $bridge);
    })->throws(RuntimeException::class)->with([
        'broken JSON' => ['{"bip": ', '172.17.0.1 172.17.0.0/16'],
        'JSON array' => ['[]', '172.17.0.1 172.17.0.0/16'],
        'no bridge gateway' => ['{}', ''],
        'bridge with shell characters' => ['{}', '172.17.0.1;reboot 172.17.0.0/16'],
    ]);
});

it('supports kernels from 5.12', function (string $kernel, bool $supported) {
    expect(InstallSysbox::isKernelSupported($kernel))->toBe($supported);
})->with([
    ['6.8.0-139-generic', true],
    ['5.15.0-1-amd64', true],
    ['5.12.0', true],
    ['5.10.0-28-amd64', false],
    ['4.19.0', false],
    ['unknown', false],
]);

describe('install commands', function () {
    it('verifies the pinned package before it installs it', function () {
        $commands = InstallSysbox::installCommands('arm64', null);
        $version = config('constants.github_runner.sysbox.version');
        $package = "/tmp/sysbox-ce_{$version}_arm64.deb";

        expect($commands)
            ->toContain("curl -fsSL -o {$package} https://github.com/nestybox/sysbox/releases/download/v{$version}/sysbox-ce_{$version}.linux_arm64.deb")
            ->toContain("echo '".config('constants.github_runner.sysbox.checksums.arm64')."  {$package}' | sha256sum -c -")
            // Sysbox needs fusermount3. Without fuse3, Debian 12 satisfies the "fuse" dependency with FUSE 2.
            ->toContain("DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y fuse3 {$package}")
            ->toContain('test -x /usr/bin/fusermount3')
            ->and(implode("\n", $commands))->not->toContain('daemon.json')
            ->not->toContain('systemctl restart');
    });

    it('writes the Docker settings before the package and keeps a backup', function () {
        $commands = InstallSysbox::installCommands('amd64', "{\n    \"bip\": \"172.17.0.1/16\"\n}\n");
        $write = collect($commands)->search(fn ($command) => str_contains($command, 'tee /etc/docker/daemon.json'));
        $install = collect($commands)->search(fn ($command) => str_contains($command, 'install -y'));

        expect($commands)->toContain('cp /etc/docker/daemon.json /etc/docker/daemon.json.before-sysbox 2>/dev/null || true')
            ->and($commands[$write])->toBe('echo '.base64_encode("{\n    \"bip\": \"172.17.0.1/16\"\n}\n").' | base64 -d | tee /etc/docker/daemon.json > /dev/null')
            ->and($write)->toBeLessThan($install);
    });

    it('keeps every command valid for servers with a non-root user', function () {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');

        $parsed = parseCommandsByLineForSudo(collect(InstallSysbox::installCommands('amd64', '{}')), $server);

        expect($parsed)
            ->toContain('echo '.base64_encode('{}').' | sudo base64 -d | sudo tee /etc/docker/daemon.json > /dev/null')
            ->toContain('sudo cp /etc/docker/daemon.json /etc/docker/daemon.json.before-sysbox 2>/dev/null || sudo true')
            ->toContain('sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y fuse3 /tmp/sysbox-ce_'.config('constants.github_runner.sysbox.version').'_amd64.deb')
            ->toContain('sudo systemctl is-active --quiet sysbox-mgr sysbox-fs')
            ->toContain('sudo test -x /usr/bin/fusermount3')
            ->toContain("sudo docker info --format '{{range \$name, \$runtime := .Runtimes}}{{\$name}} {{end}}' | sudo grep -qw sysbox-runc");
    });
});

it('checks the installation with commands that work for servers with a non-root user', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');

    // "sudo command -v" fails because command is a shell builtin, so the check must not use it.
    expect(parseCommandsByLineForSudo(collect([InstallSysbox::installedCheckCommand()]), $server))->toBe([
        "sudo systemctl is-active --quiet sysbox-mgr sysbox-fs && sudo test -x /usr/bin/fusermount3 && sudo docker info --format '{{range \$name, \$runtime := .Runtimes}}{{\$name}} {{end}}'",
    ]);
});
