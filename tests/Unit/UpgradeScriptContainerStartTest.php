<?php

/**
 * Runs the detached container restart block of the upgrade script with a fake docker.
 *
 * @return array{exitCode: int, status: string}
 */
function runUpgradeScriptContainerStart(string $script, int $composeExitCode, bool $coolifyRunning): array
{
    $dir = sys_get_temp_dir().'/upgrade-start-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch("{$dir}/status");
    $source = file_get_contents(getcwd().'/'.$script);
    preg_match('/^nohup bash -c "\n(.*?)^" >>"\$LOGFILE" 2>&1 &$/ms', $source, $match);
    $running = $coolifyRunning ? 'true' : 'false';

    $harness = <<<BASH
        LOGFILE="{$dir}/upgrade.log"
        STATUS_FILE="{$dir}/status"
        DOCKER_CONFIG_MOUNT=''
        REGISTRY_URL='docker.io'
        LATEST_HELPER_VERSION='1.0.17'
        LATEST_IMAGE='4.4.7'
        docker() {
            case "\$1" in
                run) return {$composeExitCode} ;;
                inspect) echo {$running} ;;
            esac
        }
        sleep() { :; }
        export -f docker sleep
        bash -c "
        {$match[1]}"
        BASH;

    exec('bash -c '.escapeshellarg($harness).' >/dev/null 2>&1', result_code: $exitCode);
    $status = is_file("{$dir}/status") ? file_get_contents("{$dir}/status") : '';
    exec('rm -rf '.escapeshellarg($dir));

    return ['exitCode' => $exitCode, 'status' => $status];
}

it('reports an error when the new Coolify containers do not start', function (string $script) {
    $result = runUpgradeScriptContainerStart($script, composeExitCode: 1, coolifyRunning: false);

    expect($result['exitCode'])->toBe(1)
        ->and($result['status'])->toStartWith('error|')
        ->toContain('upgrade log');
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);

it('completes the upgrade when the coolify container runs but is not healthy within the wait timeout', function (string $script) {
    $result = runUpgradeScriptContainerStart($script, composeExitCode: 1, coolifyRunning: true);

    expect($result['exitCode'])->toBe(0)
        ->and($result['status'])->toBe('');
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);

it('completes the upgrade when docker compose up succeeds', function (string $script) {
    $result = runUpgradeScriptContainerStart($script, composeExitCode: 0, coolifyRunning: true);

    expect($result['exitCode'])->toBe(0)
        ->and($result['status'])->toBe('');
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);
