<?php

use App\Actions\Server\ConfigureAutomaticSecurityUpdates;
use App\Models\Server;

/**
 * @param  list<string>  $commands
 * @return list<string>
 */
function autoSecurityUpdatesDecodedPayloads(array $commands): array
{
    preg_match_all("/echo '?([A-Za-z0-9+\/=]{8,})'? \| base64 -d/", implode("\n", $commands), $matches);

    return array_map(fn (string $payload) => base64_decode($payload, true), $matches[1]);
}

function autoSecurityUpdatesNonRootServer(): Server
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

test('only apt and dnf support automatic security updates', function (?string $packageManager, bool $supported) {
    expect(ConfigureAutomaticSecurityUpdates::supportFor($packageManager))->toBe($supported);
})->with([
    ['apt', true],
    ['dnf', true],
    ['zypper', false],
    ['apk', false],
    ['pacman', false],
    [null, false],
]);

test('apt installs unattended-upgrades and allows only the security origins at the chosen time', function () {
    $commands = ConfigureAutomaticSecurityUpdates::commands('apt', true, '04:30');
    [$config, $dropIn] = autoSecurityUpdatesDecodedPayloads($commands);

    expect($commands[0])->toContain('command -v unattended-upgrade')
        ->toContain('DEBIAN_FRONTEND=noninteractive apt-get install -y unattended-upgrades')
        ->and(implode("\n", $commands))
        ->toContain('tee /etc/apt/apt.conf.d/52coolify-unattended-upgrades > /dev/null')
        ->toContain('tee /etc/systemd/system/apt-daily-upgrade.timer.d/coolify.conf > /dev/null')
        ->toContain('chmod 644 /etc/apt/apt.conf.d/52coolify-unattended-upgrades /etc/systemd/system/apt-daily-upgrade.timer.d/coolify.conf')
        ->and($commands)->toContain('systemctl daemon-reload')
        ->toContain('systemctl enable --now apt-daily.timer apt-daily-upgrade.timer')
        ->and($config)
        ->toContain('APT::Periodic::Unattended-Upgrade "1";')
        ->toContain('#clear Unattended-Upgrade::Allowed-Origins;')
        ->toContain('#clear Unattended-Upgrade::Origins-Pattern;')
        ->toContain('"origin=Debian,codename=${distro_codename}-security,label=Debian-Security";')
        ->toContain('"origin=Ubuntu,archive=${distro_codename}-security";')
        ->not->toContain('archive=${distro_codename}-updates')
        ->and($dropIn)->toBe("[Timer]\nOnCalendar=\nOnCalendar=*-*-* 04:30\nRandomizedDelaySec=0\n");
});

test('apt disable removes only the Coolify files', function () {
    expect(ConfigureAutomaticSecurityUpdates::commands('apt', false, '03:00'))->toBe([
        'rm -f /etc/apt/apt.conf.d/52coolify-unattended-upgrades /etc/systemd/system/apt-daily-upgrade.timer.d/coolify.conf',
        'systemctl daemon-reload',
    ]);
});

test('dnf installs dnf-automatic, applies only security updates and sets the timer of dnf4 or dnf5', function () {
    $commands = ConfigureAutomaticSecurityUpdates::commands('dnf', true, '22:15');
    [$dropIn] = autoSecurityUpdatesDecodedPayloads($commands);

    expect($commands[0])->toBe('dnf install -y dnf-automatic')
        ->and($commands[1])
        ->toContain('f=/etc/dnf/automatic.conf')
        ->toContain('(upgrade_type|apply_updates)[[:space:]]*=/d')
        ->toContain('a upgrade_type = security')
        ->toContain('a apply_updates = yes')
        ->and($commands[2])
        ->toContain('t=dnf-automatic.timer; if [ -e /usr/lib/systemd/system/dnf5-automatic.timer ]; then t=dnf5-automatic.timer; fi;')
        ->toContain('/etc/systemd/system/$t.d/coolify.conf')
        ->toContain('systemctl enable --now $t')
        ->and($dropIn)->toBe("[Timer]\nOnCalendar=\nOnCalendar=*-*-* 22:15\nRandomizedDelaySec=0\n");
});

test('dnf disable stops the timer and removes the Coolify drop-in', function () {
    $commands = ConfigureAutomaticSecurityUpdates::commands('dnf', false, '03:00');

    expect($commands)->toHaveCount(1)
        ->and($commands[0])
        ->toContain('systemctl disable --now $t')
        ->toContain('rm -f /etc/systemd/system/$t.d/coolify.conf')
        ->toContain('systemctl daemon-reload')
        ->not->toContain('automatic.conf');
});

test('every command runs as root for a non-root SSH user', function (string $packageManager, bool $enabled) {
    $commands = ConfigureAutomaticSecurityUpdates::commands($packageManager, $enabled, '03:00');

    $parsed = parseCommandsByLineForSudo(collect($commands), autoSecurityUpdatesNonRootServer());

    foreach ($parsed as $line) {
        $isSudoLine = str_starts_with($line, 'sudo ') && ! str_contains($line, ' | ');
        $isSudoShellScript = isSingleSudoShellScript($line) && substr_count($line, 'sudo') === 1;
        $isSudoTeePipe = preg_match("/^echo '[A-Za-z0-9+\/=]+' \| sudo base64 -d \| sudo tee \/etc\/\S+ > \/dev\/null$/", $line) === 1;

        expect($isSudoLine || $isSudoShellScript || $isSudoTeePipe)->toBeTrue("Line does not run as root: {$line}");
    }
})->with([
    'apt enabled' => ['apt', true],
    'apt disabled' => ['apt', false],
    'dnf enabled' => ['dnf', true],
    'dnf disabled' => ['dnf', false],
]);

test('handle rejects a time that is not HH:MM', function (string $time) {
    ConfigureAutomaticSecurityUpdates::run(new Server, 'apt', true, $time);
})->throws(InvalidArgumentException::class)->with(['24:00', '3:00', '03:60', '03:00; reboot', '']);

test('handle rejects an unsupported package manager', function () {
    ConfigureAutomaticSecurityUpdates::run(new Server, 'zypper', true, '03:00');
})->throws(RuntimeException::class);
