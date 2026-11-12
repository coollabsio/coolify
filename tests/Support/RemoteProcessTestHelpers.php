<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The text of a faked process: its command line and its stdin. SSH command batches go to the
 * server on stdin, so assertions on remote commands must read both.
 */
function remoteProcessScript(PendingProcess $process): string
{
    $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

    return is_string($process->input) && $process->input !== '' ? $command."\n".$process->input : $command;
}

/**
 * Process::fake() with command patterns that also match the SSH command batch on stdin.
 * Unmatched processes get an empty successful result.
 *
 * @param  array<string, mixed>  $handlers  pattern => result or closure, as for Process::fake()
 */
function fakeRemoteProcesses(array $handlers): void
{
    Process::fake(function (PendingProcess $process) use ($handlers) {
        $script = remoteProcessScript($process);
        foreach ($handlers as $pattern => $handler) {
            if (is_numeric($pattern) || $pattern === '*' || Str::is($pattern, $script)) {
                return $handler instanceof Closure ? $handler($process) : $handler;
            }
        }

        return Process::result();
    });
}

/**
 * The output of LocalFileVolume::remoteFileStateCommand() when every checked path has the same state.
 */
function remoteFileStatesOutput(string $state, int $paths = 20): string
{
    return implode("\n", array_map(fn (int $index) => "{$index}:{$state}", range(1, $paths)));
}
