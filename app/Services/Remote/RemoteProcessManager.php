<?php

/** @noinspection ALL */

namespace App\Services\Remote;

use App\Models\Server;
use App\Services\Shared\Models\ExecutedProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Collection;

/**
 * Class RemoteProcessManager
 * This class should be used to execute remote processes.
 */
class RemoteProcessManager
{
    private Server $server;

    private InstantRemoteProcessFactory $instantRemoteProcessFactory;

    private RemoteProcessExecutionerManager $executioner;

    public function __construct(Server $server, InstantRemoteProcessFactory $remoteProcessFactory,
        RemoteProcessExecutionerManager $executionerManager)
    {
        $this->server = $server;
        $this->instantRemoteProcessFactory = $remoteProcessFactory;
        $this->executioner = $executionerManager;

    }

    public function execute(Collection|array|string $commands, bool $throwError = true): ExecutedProcessResult
    {
        $commands = $this->getCommandCollection($commands);

        $generatedCommand = $this->instantRemoteProcessFactory->generateCommandFromCollection($this->server, $commands);

        $executedResult = $this->executioner->execute($generatedCommand, $throwError);

        return new ExecutedProcessResult($generatedCommand, $executedResult);
    }

    public function executeWithCallback(string $command, ?callable $output = null): InvokedProcess
    {
        $process = $this->executioner->createAwaitingProcess($command, 3600, 3600, $output);

        return $process;
    }

    private function getCommandCollection(Collection|array|string $commands): Collection
    {
        if ($commands instanceof Collection) {
            return $commands;
        }

        if (is_array($commands)) {
            return collect($commands);
        }

        return collect([$commands]);
    }
}
