<?php

use App\Actions\Sentinel\EnsureFluxCertificateAuthority;
use App\Actions\Server\InstallSentinelHost;
use App\Actions\Server\RepairSentinelFluxTrust;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->authority = EnsureFluxCertificateAuthority::run();
    $this->directory = sys_get_temp_dir().'/coolify-sentinel-rollback-'.bin2hex(random_bytes(8));
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('restores a failed fresh install to inactive and disabled without Sentinel files', function () {
    $script = InstallSentinelHost::installationScript(
        'sentinel-token',
        'https://coolify.example/api/v1/sentinel',
        'ghcr.io/coollabsio/sentinel-host:main',
        $this->authority->certificate_pem,
        $this->authority->version,
    );
    $sandbox = sentinelTrustRollbackSandbox($this->directory, $script, active: false, enabled: false);

    $result = runSentinelTrustFailure($sandbox);

    expect($result->getExitCode())->toBe(1)
        ->and(file_get_contents($sandbox['active']))->toBe('inactive')
        ->and(file_get_contents($sandbox['enabled']))->toBe('disabled')
        ->and($sandbox['root'].'/usr/local/bin/sentinel')->not->toBeFile()
        ->and($sandbox['root'].'/etc/systemd/system/sentinel.service')->not->toBeFile()
        ->and($sandbox['root'].'/etc/coolify/sentinel.env')->not->toBeFile()
        ->and($sandbox['root'].'/etc/coolify/sentinel-flux-ca.pem')->not->toBeFile()
        ->and($sandbox['root'].'/etc/coolify/sentinel-flux-ca.version')->not->toBeFile();

    expect(sentinelTrustCalls($sandbox))->toContain('stop sentinel.service')
        ->toContain('disable sentinel.service');
});

it('restores files modes and an enabled-state-disabled active Sentinel after a failed update', function () {
    $script = InstallSentinelHost::installationScript(
        'sentinel-token',
        'https://coolify.example/api/v1/sentinel',
        'ghcr.io/coollabsio/sentinel-host:main',
        $this->authority->certificate_pem,
        $this->authority->version,
    );
    $sandbox = sentinelTrustRollbackSandbox($this->directory, $script, active: true, enabled: false);
    $previous = writeSentinelTrustFiles($sandbox['root']);

    $result = runSentinelTrustFailure($sandbox);

    expect($result->getExitCode())->toBe(1)
        ->and(file_get_contents($sandbox['active']))->toBe('active')
        ->and(file_get_contents($sandbox['enabled']))->toBe('disabled');
    assertSentinelTrustFiles($previous);

    $calls = sentinelTrustCalls($sandbox);
    expect(array_search('stop sentinel.service', $calls, true))
        ->toBeLessThan(sentinelTrustRollbackMoveIndex($calls));
});

it('restores an inactive enabled Sentinel and preserves its token and endpoint after a failed repair', function () {
    $script = RepairSentinelFluxTrust::repairScript($this->authority->certificate_pem, $this->authority->version);
    $sandbox = sentinelTrustRollbackSandbox($this->directory, $script, active: false, enabled: true);
    $previous = writeSentinelTrustFiles($sandbox['root']);

    $result = runSentinelTrustFailure($sandbox);

    expect($result->getExitCode())->toBe(1)
        ->and(file_get_contents($sandbox['active']))->toBe('inactive')
        ->and(file_get_contents($sandbox['enabled']))->toBe('enabled');
    assertSentinelTrustFiles($previous);
    expect(file_get_contents($sandbox['root'].'/etc/coolify/sentinel.env'))
        ->toContain('TOKEN=previous-token')
        ->toContain('PUSH_ENDPOINT=https://previous.example/sentinel');
    expect(sentinelTrustCalls($sandbox))->toContain('stop sentinel.service');
});

/**
 * @return array{root: string, commands: string, state: string, active: string, enabled: string, script: string}
 */
function sentinelTrustRollbackSandbox(string $directory, string $script, bool $active, bool $enabled): array
{
    $root = $directory.'/root';
    $commands = $directory.'/commands';
    $state = $directory.'/state';
    File::ensureDirectoryExists($commands);
    File::ensureDirectoryExists($state);
    File::ensureDirectoryExists($root.'/etc/systemd/system');
    File::ensureDirectoryExists($root.'/usr/local/bin');
    File::ensureDirectoryExists($root.'/tmp');
    File::put($state.'/active', $active ? 'active' : 'inactive');
    File::put($state.'/enabled', $enabled ? 'enabled' : 'disabled');
    File::put($state.'/calls', '');

    writeSentinelTrustCommand($commands, 'systemctl', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$COOLIFY_SENTINEL_TEST_STATE/calls"
case "$1" in
    daemon-reload) exit 0 ;;
    enable) printf enabled > "$COOLIFY_SENTINEL_TEST_STATE/enabled" ;;
    disable) printf disabled > "$COOLIFY_SENTINEL_TEST_STATE/enabled" ;;
    start|restart) printf active > "$COOLIFY_SENTINEL_TEST_STATE/active" ;;
    stop) printf inactive > "$COOLIFY_SENTINEL_TEST_STATE/active" ;;
    is-active) test "$(cat "$COOLIFY_SENTINEL_TEST_STATE/active")" = active ;;
    is-enabled) test "$(cat "$COOLIFY_SENTINEL_TEST_STATE/enabled")" = enabled ;;
esac
SH);
    writeSentinelTrustCommand($commands, 'docker', <<<'SH'
#!/bin/sh
set -eu
case "$1" in
    pull) exit 0 ;;
    create) printf container-id ;;
    cp) printf sentinel-binary > "$3" ;;
    container) exit 0 ;;
esac
SH);
    writeSentinelTrustCommand($commands, 'curl', <<<'SH'
#!/bin/sh
printf '%s\n' curl >> "$COOLIFY_SENTINEL_TEST_STATE/calls"
exit 1
SH);
    writeSentinelTrustCommand($commands, 'sleep', "#!/bin/sh\nexit 0\n");
    writeSentinelTrustCommand($commands, 'journalctl', "#!/bin/sh\nexit 0\n");
    writeSentinelTrustCommand($commands, 'mv', <<<'SH'
#!/bin/sh
printf 'mv %s\n' "$*" >> "$COOLIFY_SENTINEL_TEST_STATE/calls"
exec /bin/mv "$@"
SH);

    $sandboxScript = str_replace(
        ['/etc/coolify', '/etc/systemd/system/sentinel.service', '/usr/local/bin/sentinel', '/app/db', '/tmp/coolify-sentinel.'],
        ["{$root}/etc/coolify", "{$root}/etc/systemd/system/sentinel.service", "{$root}/usr/local/bin/sentinel", "{$root}/app/db", "{$root}/tmp/coolify-sentinel."],
        $script,
    );
    $scriptPath = $directory.'/script.sh';
    File::put($scriptPath, $sandboxScript);

    return [
        'root' => $root,
        'commands' => $commands,
        'state' => $state,
        'active' => $state.'/active',
        'enabled' => $state.'/enabled',
        'script' => $scriptPath,
    ];
}

function writeSentinelTrustCommand(string $directory, string $name, string $contents): void
{
    $path = $directory.'/'.$name;
    File::put($path, $contents);
    chmod($path, 0755);
}

/** @param array{commands: string, state: string, script: string} $sandbox */
function runSentinelTrustFailure(array $sandbox): SymfonyProcess
{
    $process = new SymfonyProcess(['bash', $sandbox['script']]);
    $process->setEnv([
        'PATH' => $sandbox['commands'].':'.getenv('PATH'),
        'COOLIFY_SENTINEL_TEST_STATE' => $sandbox['state'],
    ]);
    $process->run();

    return $process;
}

/** @param array{root: string} $sandbox */
function writeSentinelTrustFiles(string $root): array
{
    $files = [
        $root.'/usr/local/bin/sentinel' => ['previous-binary', 0750],
        $root.'/etc/systemd/system/sentinel.service' => ['previous-unit', 0640],
        $root.'/etc/coolify/sentinel.env' => ["TOKEN=previous-token\nPUSH_ENDPOINT=https://previous.example/sentinel\nFLUX_CA_PATH=/previous.pem\nFLUX_TRUST_BUNDLE_VERSION=9\n", 0600],
        $root.'/etc/coolify/sentinel-flux-ca.pem' => ['previous-ca', 0640],
        $root.'/etc/coolify/sentinel-flux-ca.version' => ['9', 0600],
    ];

    foreach ($files as $path => [$contents, $mode]) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        chmod($path, $mode);
    }

    return $files;
}

/** @param array<string, array{0: string, 1: int}> $files */
function assertSentinelTrustFiles(array $files): void
{
    foreach ($files as $path => [$contents, $mode]) {
        expect(file_get_contents($path))->toBe($contents)
            ->and(fileperms($path) & 0777)->toBe($mode);
    }
}

/** @param array{commands: string} $sandbox
 * @return array<int, string>
 */
function sentinelTrustCalls(array $sandbox): array
{
    return array_values(array_filter(explode("\n", trim((string) file_get_contents($sandbox['state'].'/calls')))));
}

/** @param array<int, string> $calls */
function sentinelTrustRollbackMoveIndex(array $calls): int
{
    foreach ($calls as $index => $call) {
        if (str_starts_with($call, 'mv ') && str_contains($call, '.sentinel-install.')) {
            return $index;
        }
    }

    return -1;
}
