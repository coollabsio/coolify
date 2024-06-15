<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Str;

class RemoteCommandGeneratorService
{
    private SshCommandGeneratorService $sshCommandFactory;

    public function __construct(SshCommandGeneratorService $sshCommandFactory)
    {
        $this->sshCommandFactory = $sshCommandFactory;
    }

    public function create(Server $server, string $command): string
    {
        $remoteCommand = $this->createRemoteCommand($server, $command);

        $sshCommand = $this->sshCommandFactory->generateSshCommand($server, $remoteCommand);

        return $sshCommand;
    }

    private function createRemoteCommand(Server $server, string $command): string
    {
        if ($server->isNonRoot()) {
            return $this->parseLineForSudo($command, $server);
        }

        return $command;
    }

    public function parseLineForSudo(string $command, Server $server): string
    {
        $newCommand = $command;

        if (Str::startsWith($newCommand, 'docker exec')) {
            $newCommand = Str::replaceFirst('docker exec', 'sudo docker exec', $newCommand);
        }

        if (! str($newCommand)->startSwith('cd') && ! str($newCommand)->startSwith('command')) {
            $newCommand = "sudo $newCommand";
        }
        if (Str::startsWith($newCommand, 'sudo mkdir -p')) {
            $newCommand = "$newCommand && sudo chown -R $server->user:$server->user ".Str::after($newCommand, 'sudo mkdir -p').' && sudo chmod -R o-rwx '.Str::after($newCommand, 'sudo mkdir -p');
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
