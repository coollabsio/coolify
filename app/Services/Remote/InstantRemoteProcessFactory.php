<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Collection;

class InstantRemoteProcessFactory
{
    private Server $server;
    private RemoteCommandGeneratorFactory $remoteCommandGenerator;
    private SshCommandFactory $sshCommandFactory;

    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->remoteCommandGenerator = RemoteCommandGeneratorFactory::new();
        $this->sshCommandFactory = new SshCommandFactory();
    }

    public function getCommandOutput(Collection|array $commands): string
    {
        $timeout = config('constants.ssh.command_timeout');

        if ($commands instanceof Collection) {
            $commandsToExecute = $commands;
        } else {
            $commandsToExecute = collect($commands);
        }

        if($this->server->isNonRoot()) {
            $commandsToExecute = $commandsToExecute->map(function($command) {
                return $this->remoteCommandGenerator->parseLineForSudo($command, $this->server);
            });
        }

        $commandsAsSingleLine = $commandsToExecute->implode("\n");

        $sshCommand = $this->sshCommandFactory->generateSshCommand($this->server, $commandsAsSingleLine);

        return $sshCommand;

    }
}
