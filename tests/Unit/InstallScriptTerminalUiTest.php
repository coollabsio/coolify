<?php

use Symfony\Component\Process\Process;

function installScriptUiHelpers(string $path): string
{
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$path);
    $start = strpos($script, '# Terminal UI');
    $end = strpos($script, '# fd 3 is the terminal');

    return substr($script, $start, $end - $start);
}

it('shows every installer step with the terminal UI and sends command output to the log', function (string $path) {
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    expect($script)
        ->toContain('TOTAL_STEPS=9')
        ->toContain('exec >>"$INSTALLATION_LOG_WITH_DATE" 2>&1')
        ->toContain('trap ui_on_exit EXIT')
        ->toContain('"true" 3>&-')
        ->not->toContain('tee -a $INSTALLATION_LOG_WITH_DATE')
        ->not->toContain('getAJoke')
        ->and(substr_count($script, 'step_start "'))->toBe(10)
        ->and(preg_match_all('/^step_done(?!\()/m', $script))->toBe(9);
})->with([
    'stable install script' => ['scripts/install.sh'],
    'nightly install script' => ['other/nightly/install.sh'],
]);

it('renders aligned step lines, warnings and failures without a terminal', function (string $path) {
    $demo = installScriptUiHelpers($path).<<<'BASH'
        set -e
        log() { :; }
        log_section() { :; }
        INSTALLATION_LOG_WITH_DATE=$(mktemp)
        exec 3>&1
        exec >>"$INSTALLATION_LOG_WITH_DATE" 2>&1
        trap ui_on_exit EXIT
        step_start "Installing required packages" "curl wget git jq openssl"
        step_done
        step_start "Pulling images" "coolify · postgres · redis · helper"
        warn "SSH PermitRootLogin is disabled."
        step_done
        step_start "Starting Coolify" "v4.0.0"
        echo "ERROR: Coolify container is not healthy"
        exit 1
        BASH;

    $process = new Process(['bash', '-c', $demo], env: ['LC_ALL' => 'C', 'TERM' => 'dumb']);
    $process->run();

    $output = $process->getOutput();
    $stepLines = array_values(array_filter(explode("\n", $output), fn (string $line) => str_contains($line, '/9  ')));

    expect($process->getExitCode())->toBe(1)
        ->and($output)->not->toContain("\033[")
        ->toContain('1/9  Installing required packages  curl wget git jq openssl')
        ->toContain('2/9  Pulling images  coolify · postgres · redis · helper')
        ->toContain('! SSH PermitRootLogin is disabled.')
        ->toContain('3/9  Starting Coolify  v4.0.0')
        ->toContain('Installation failed.')
        ->toContain('ERROR: Coolify container is not healthy')
        ->and($stepLines)->toHaveCount(3)
        ->and($stepLines[0])->toEndWith('✓')
        ->and($stepLines[1])->toEndWith('✓')
        ->and($stepLines[2])->toEndWith('✗')
        ->and(array_map(fn (string $line) => mb_strlen($line), $stepLines))->each->toBe(80);
})->with([
    'stable install script' => ['scripts/install.sh'],
    'nightly install script' => ['other/nightly/install.sh'],
]);
