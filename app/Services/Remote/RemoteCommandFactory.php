<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Str;

class RemoteCommandFactory
{
    private SshCommandFactory $sshCommandFactory;

    public function __construct(SshCommandFactory $sshCommandFactory)
    {
        $this->sshCommandFactory = $sshCommandFactory;
    }

    public function create(Server $server, string $command): string
    {
        $remoteCommand = $this->createRemoteCommand($server, $command);


        $sshCommand = $this->sshCommandFactory->create($server, $remoteCommand);
    }

    private function createRemoteCommand(Server $server, string $command): string
    {
        if ($server->isNonRoot()) {
            if (Str::startsWith($command, 'docker exec')) {
                return Str::replaceFirst('docker exec', 'sudo docker exec', $command);
            }

            return $this->parseLineForSudo($command, $server);
        }

        return $command;
    }

    private function parseLineForSudo(string $command, Server $server): string
    {
        $newCommand = $command;

        if (!str($newCommand)->startSwith('cd') && !str($newCommand)->startSwith('command')) {
            $newCommand = "sudo $newCommand";
        }
        if (Str::startsWith($newCommand, 'sudo mkdir -p')) {
            $newCommand = "$newCommand && sudo chown -R $server->user:$server->user " . Str::after($newCommand, 'sudo mkdir -p') . ' && sudo chmod -R o-rwx ' . Str::after($newCommand, 'sudo mkdir -p');
        }
        if (str($newCommand)->contains('$(') || str($newCommand)->contains('`')) {
            $newCommand = str($newCommand)->replace('$(', '$(sudo ')->replace('`', '`sudo ')->value();
        }
        if (str($newCommand)->contains('||')) {
            $newCommand = str($newCommand)->replace('||', '|| sudo ')->value();
        }
        if (str($newCommand)->contains('&&')) {
            $newCommand = str($newCommand)->replace('&&', '&& sudo ')->value();
        }

        return $newCommand;
    }
}
