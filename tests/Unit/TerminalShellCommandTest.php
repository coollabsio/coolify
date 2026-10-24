<?php

use App\Services\TerminalSessionService;
use Symfony\Component\Process\Process;

function runTerminalShellCommand(string $shellName): string
{
    $home = sys_get_temp_dir().'/terminal-shell-'.uniqid();
    mkdir($home);
    $shell = "{$home}/{$shellName}";
    file_put_contents($shell, "#!/bin/sh\necho {$shellName}-ran\nexit 1\n");
    chmod($shell, 0755);

    $command = (fn () => $this->shellCommand())->call(new TerminalSessionService);

    $process = new Process(['sh', '-c', $command], null, ['HOME' => $home, 'SHELL' => $shell]);
    $process->setInput("echo fallback-ran\n");
    $process->run();

    return $process->getOutput();
}

it('falls back to a usable shell when $SHELL is a login-disabling shell', function (string $shellName) {
    $output = runTerminalShellCommand($shellName);

    expect($output)->toContain('fallback-ran')
        ->not->toContain("{$shellName}-ran");
})->with(['nologin', 'false']);

it('starts $SHELL when it is a usable shell', function () {
    expect(runTerminalShellCommand('zsh'))->toContain('zsh-ran');
});
