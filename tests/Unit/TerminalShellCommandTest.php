<?php

use App\Services\TerminalSessionService;
use Symfony\Component\Process\Process;

function runTerminalShellCommand(string $shellName, int $exitCode = 1): Process
{
    $home = sys_get_temp_dir().'/terminal-shell-'.uniqid();
    mkdir($home);
    $shell = "{$home}/{$shellName}";
    file_put_contents($shell, "#!/bin/sh\necho {$shellName}-ran\nexit {$exitCode}\n");
    chmod($shell, 0755);

    $command = (fn () => $this->shellCommand())->call(new TerminalSessionService);

    $process = new Process(['sh', '-c', $command], null, ['HOME' => $home, 'SHELL' => $shell]);
    $process->setInput("echo fallback-ran\n");
    $process->run();

    return $process;
}

it('falls back to a usable shell when $SHELL is a login-disabling shell', function (string $shellName) {
    $process = runTerminalShellCommand($shellName);

    expect($process->getOutput())->toContain('fallback-ran')
        ->not->toContain("{$shellName}-ran");
    expect($process->getExitCode())->toBe(0);
})->with(['nologin', 'false']);

it('starts $SHELL when it is a usable shell', function () {
    $process = runTerminalShellCommand('zsh', exitCode: 0);

    expect($process->getOutput())->toContain('zsh-ran');
    expect($process->getExitCode())->toBe(0);
});
