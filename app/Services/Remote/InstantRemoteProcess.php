<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Facades\Process;

/**
 * Class InstantRemoteProcess
 *
 * This class is responsible for executing a remote command on a server and returning the output.
 * @package App\Services\Remote
 */
class InstantRemoteProcess
{
    private Server $server;
    private string $command;

    public function __construct(Server $server, string $command)
    {
        $this->server = $server;
        $this->command = $command;
    }
    public function getOutput(bool $trowExceptionOnError = true): string | null {
        $timeout = config('constants.ssh.command_timeout');
        $process = Process::timeout($timeout)->run($this->command);

        $output = trim($process->output());
        $exitCode = $process->exitCode();

        if($exitCode !== 0) {
            if(!$trowExceptionOnError) {
                return null;
            }

            // TODO: Refactor to Error Exclude Service.
            return excludeCertainErrors($process->errorOutput(), $exitCode);
        }

        if($output === 'null') {
            return null;
        }

        return $output;
    }
}
