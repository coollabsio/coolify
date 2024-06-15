<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Collection;

class InstantRemoteProcessFactory
{
    private RemoteCommandGeneratorService $remoteCommandGenerator;

    private SshCommandGeneratorService $sshCommandFactory;

    public function __construct(RemoteCommandGeneratorService $remoteCommandGenerator, SshCommandGeneratorService $sshCommandFactory)
    {
        $this->remoteCommandGenerator = $remoteCommandGenerator;
        $this->sshCommandFactory = $sshCommandFactory;
    }

    public function generateCommand(Server $server, string $command): string
    {
        if ($server->isNonRoot()) {
            $command = $this->remoteCommandGenerator->parseLineForSudo($command, $server);
        }

        if (! $this->shouldUseSsh($server)) {
            return $command;
        }

        $sshCommand = $this->sshCommandFactory->generateSshCommand($server, $command);

        return $sshCommand;
    }

    public function generateCommandFromCollection(Server $server, Collection $commands): string
    {
        $commandsToExecute = $commands;

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
