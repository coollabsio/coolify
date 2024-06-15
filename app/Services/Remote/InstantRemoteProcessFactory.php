<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Collection;

class InstantRemoteProcessFactory
{
    private RemoteCommandGeneratorService $remoteCommandGenerator;

    private SshCommandService $sshCommandFactory;

    public function __construct(RemoteCommandGeneratorService $remoteCommandGenerator, SshCommandService $sshCommandFactory)
    {
        $this->remoteCommandGenerator = $remoteCommandGenerator;
        $this->sshCommandFactory = $sshCommandFactory;
    }

    public function generateCommand(Server $server, Collection|array $commands): string
    {
        $timeout = config('constants.ssh.command_timeout');

        if ($commands instanceof Collection) {
            $commandsToExecute = $commands;
        } else {
            $commandsToExecute = collect($commands);
        }

        if ($server->isNonRoot()) {
            $commandsToExecute = $commandsToExecute->map(function ($command) use ($server) {
                return $this->remoteCommandGenerator->parseLineForSudo($command, $server);
            });
        }

        $commandsAsSingleLine = $commandsToExecute->implode("\n");

        if (! $this->shouldUseSsh($server)) {
            return $commandsAsSingleLine;
        }

        $sshCommand = $this->sshCommandFactory->generateSshCommand($server, $commandsAsSingleLine);

        return $sshCommand;

    }

    private function shouldUseSsh(Server $server): bool
    {
        // not strict checking because ip is Stringable
        return $server->ip != '127.0.0.1';
    }
}
