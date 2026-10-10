<?php

use App\Services\CoolifyUpgradeStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function createUpgradeTestBinary(string $directory, string $name, string $contents): void
{
    file_put_contents("{$directory}/{$name}", $contents);
    chmod("{$directory}/{$name}", 0755);
}

function runUpgradeWithComposeResult(string $scriptPath, int $composeExitCode): array
{
    $workingDirectory = sys_get_temp_dir().'/coolify-upgrade-test-'.Str::random(12);
    $dataDirectory = $workingDirectory.'/data/coolify';
    $sourceDirectory = $dataDirectory.'/source';
    $binDirectory = $workingDirectory.'/bin';

    File::makeDirectory($sourceDirectory, 0755, true);
    File::makeDirectory($dataDirectory.'/ssh', 0755, true);
    File::makeDirectory($binDirectory, 0755, true);

    file_put_contents($sourceDirectory.'/.env', implode("\n", [
        'PUSHER_APP_ID=test',
        'PUSHER_APP_KEY=test',
        'PUSHER_APP_SECRET=test',
        '',
    ]));

    $script = file_get_contents(base_path($scriptPath));
    if ($script === false) {
        throw new RuntimeException("Unable to read {$scriptPath}");
    }

    $testScript = $workingDirectory.'/upgrade.sh';
    file_put_contents($testScript, str_replace('/data/coolify', $dataDirectory, $script));
    chmod($testScript, 0755);

    createUpgradeTestBinary($binDirectory, 'curl', <<<'BASH'
#!/bin/bash
while [ "$#" -gt 0 ]; do
    if [ "$1" = '-o' ]; then
        shift
        mkdir -p "$(dirname "$1")"
        : > "$1"
        exit 0
    fi
    shift
done
exit 1
BASH);

    createUpgradeTestBinary($binDirectory, 'docker', <<<'BASH'
#!/bin/bash
case "$1" in
    compose)
        printf '%s\n' 'docker.io/coollabsio/coolify:test'
        exit 0
        ;;
    network|pull|ps)
        exit 0
        ;;
    run)
        if [ "${MOCK_COMPOSE_EXIT_CODE}" -ne 0 ]; then
            printf '%s\n' 'failed to bind host port 6001: address already in use' >&2
        fi
        exit "${MOCK_COMPOSE_EXIT_CODE}"
        ;;
esac
exit 0
BASH);

    createUpgradeTestBinary($binDirectory, 'nohup', <<<'BASH'
#!/bin/bash
"$@"
exit_code=$?
printf '%s' "$exit_code" > "$MOCK_NOHUP_EXIT_FILE"
exit "$exit_code"
BASH);

    createUpgradeTestBinary($binDirectory, 'sleep', <<<'BASH'
#!/bin/bash
if [ "$1" = '2' ]; then
    for _ in $(seq 1 500); do
        [ -f "$MOCK_NOHUP_EXIT_FILE" ] && exit 0
        /bin/sleep 0.01
    done
    exit 1
fi
exit 0
BASH);

    createUpgradeTestBinary($binDirectory, 'chown', <<<'BASH'
#!/bin/bash
exit 0
BASH);

    $nohupExitFile = $workingDirectory.'/nohup-exit';
    $process = new Process(
        ['bash', $testScript, '4.3.15', '1.0.17', 'docker.io', 'true'],
        env: [
            'PATH' => $binDirectory.':'.getenv('PATH'),
            'MOCK_COMPOSE_EXIT_CODE' => (string) $composeExitCode,
            'MOCK_NOHUP_EXIT_FILE' => $nohupExitFile,
        ],
    );
    $process->run();

    $logs = glob($sourceDirectory.'/upgrade-*.log');
    $result = [
        'process_exit_code' => $process->getExitCode(),
        'nohup_exit_code' => is_file($nohupExitFile) ? (int) file_get_contents($nohupExitFile) : null,
        'status' => is_file($sourceDirectory.'/.upgrade-status') ? file_get_contents($sourceDirectory.'/.upgrade-status') : null,
        'log' => $logs === false || $logs === [] ? '' : file_get_contents($logs[0]),
    ];

    File::deleteDirectory($workingDirectory);

    return $result;
}

it('records a failed container start instead of reporting a successful upgrade', function (string $scriptPath) {
    $result = runUpgradeWithComposeResult($scriptPath, 42);
    $parsedStatus = CoolifyUpgradeStatus::fromFile(
        content: $result['status'] ?? '',
        runningVersion: '4.3.14',
        targetVersion: '4.3.15',
    );

    expect($result['process_exit_code'])->toBe(0)
        ->and($result['nohup_exit_code'])->toBe(42)
        ->and($result['status'])->toStartWith('error|Failed to start Coolify containers (exit code 42)|')
        ->and($result['log'])->toContain('address already in use')
        ->and($result['log'])->not->toContain('Upgrade complete')
        ->and($result['log'])->not->toContain('completed successfully')
        ->and($parsedStatus)->toMatchArray([
            'status' => 'error',
            'message' => 'Failed to start Coolify containers (exit code 42)',
        ]);
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('still records a successful upgrade when the containers start', function (string $scriptPath) {
    $result = runUpgradeWithComposeResult($scriptPath, 0);

    expect($result['process_exit_code'])->toBe(0)
        ->and($result['nohup_exit_code'])->toBe(0)
        ->and($result['status'])->toBeNull()
        ->and($result['log'])->toContain('Step 6/6: Upgrade complete')
        ->and($result['log'])->toContain('Coolify upgrade completed successfully');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);
