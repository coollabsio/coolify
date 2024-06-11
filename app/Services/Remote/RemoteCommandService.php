<?php

namespace App\Services\Remote;

use App\Domain\Remote\Commands\RemoteCommand;
use App\Models\Server;
use App\Services\Contracts\Remote\RemoteCommandContract;
use Illuminate\Support\Collection;

class RemoteCommandService implements RemoteCommandContract
{
    private int $batchCounter = 0;

    /**
     * Execute a remote command.
     *
     * @param Server $server The server to execute the command on.
     * @param array $commands The commands to execute.
     * @return void
     */
    public function executeRemoteCommand(Server $server, array $commands): void
    {
        $this->batchCounter++;

        $commandsToExecute = collect($commands);

        $this->validateCommands($commandsToExecute);

        $commandsToExecute->each(function (RemoteCommand $command) use ($server) {
            $this->executeCommand($server, $command);
        });
    }

    private function executeCommand(Server $server, RemoteCommand $command)
    {

    }

    private function validateCommands(Collection $commands)
    {
        $commands->each(function ($command) {
            if (!$command instanceof RemoteCommand) {
                throw new \RuntimeException(sprintf("Command is not an instance of %s", RemoteCommand::class));
            }

            if(strlen($command->command) === 0) {
                throw new \RuntimeException("Command is not set");
            }
        });
    }

}

