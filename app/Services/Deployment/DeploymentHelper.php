<?php

namespace App\Services\Deployment;

use App\Domain\Deployment\DeploymentOutput;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Exceptions\DeploymentCommandFailedException;
use App\Exceptions\RemoteCommandInvalidException;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Services\Remote\InstantRemoteProcessFactory;
use App\Services\Remote\Provider\RemoteProcessProvider;
use App\Services\Remote\RemoteProcessExecutionerManager;
use App\Services\Remote\RemoteProcessManager;
use App\Services\Shared\Models\ExecutedProcessResult;
use Illuminate\Support\Collection;

class DeploymentHelper
{
    private RemoteProcessManager $remoteProcessManager;

    private static int $batchCounter = 0;

    private InstantRemoteProcessFactory $instantRemoteProcessFactory;

    private Server $server;

    private RemoteProcessExecutionerManager $executioner;

    public function __construct(Server $server, RemoteProcessProvider $processProvider, InstantRemoteProcessFactory $instantRemoteProcessFactory, RemoteProcessExecutionerManager $executionerManager)
    {
        $this->server = $server;
        $this->remoteProcessManager = $processProvider->forServer($server);
        $this->instantRemoteProcessFactory = $instantRemoteProcessFactory;
        $this->executioner = $executionerManager;
    }

    public function executeCommand(Collection|array|string $command, bool $throwError = true): ExecutedProcessResult
    {
        return $this->remoteProcessManager->execute($command, $throwError);
    }

    public function executeAndSave(Collection|array|string $command, ApplicationDeploymentQueue $applicationDeploymentQueue, Collection &$savedOutputs): void
    {
        self::$batchCounter++;

        $commands = $this->getCommandCollection($command);

        $this->validateCommands($commands);

        $commands->each(function (RemoteCommand $command) use ($applicationDeploymentQueue, &$savedOutputs) {
            $commandToExecute = $this->instantRemoteProcessFactory->generateCommand($this->server, $command->command);

            $process = $this->remoteProcessManager->executeWithCallback($commandToExecute, function (string $type, string $output) use ($command, $applicationDeploymentQueue, &$savedOutputs) {
                $output = str($output)->trim();
                if ($output->startsWith('╔')) {
                    $output = "\n".$output;
                }

                $deploymentOutput = new DeploymentOutput(
                    $command->command,
                    $output,
                    $type === 'err' ? 'stderr' : 'stdout',
                    $command->hidden,
                    self::$batchCounter
                );

                $this->saveLogToDeploymentQueue($deploymentOutput, $applicationDeploymentQueue);

                if (strlen($command->save) > 0) {
                    if (! $savedOutputs->has($command->save)) {
                        $savedOutputs->put($command->save, str());
                    }

                    $outputToSave = str($output)->trim();

                    if ($command->append) {
                        $savedOutputs->put($command->save, $savedOutputs->get($command->save).$outputToSave);
                    } else {
                        $savedOutputs->put($command->save, $outputToSave);
                    }

                }

            });

            $applicationDeploymentQueue->update([
                'current_process_id' => $process->id(),
            ]);

            $processResult = $process->wait();

            if ($processResult->exitCode() !== 0 && $command->ignoreErrors === false) {
                $applicationDeploymentQueue->setFailed();
                $applicationDeploymentQueue->save();

                throw new DeploymentCommandFailedException(sprintf('Command "%s" failed with exit code %s', $command->command, $processResult->exitCode()));
            }
        });

    }

    private function validateCommands(Collection $commands): void
    {
        $commands->each(function ($command) {
            if (! $command instanceof RemoteCommand) {
                throw new RemoteCommandInvalidException(sprintf('Command is not an instance of %s', RemoteCommand::class));
            }

            if (strlen($command->command) === 0) {
                throw new RemoteCommandInvalidException('Command is not set');
            }
        });
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

    private function saveLogToDeploymentQueue(DeploymentOutput $deploymentOutput, ApplicationDeploymentQueue $applicationDeploymentQueue)
    {
        $previousLogs = [];

        if ($applicationDeploymentQueue->logs) {
            $previousLogs = json_decode($applicationDeploymentQueue->logs, associative: true, flags: JSON_THROW_ON_ERROR);
            $deploymentOutput->setOrder(count($previousLogs) + 1);
        }

        $previousLogs[] = $deploymentOutput->toArray();

        // TODO: Eventually, 'logs' should be casted to array.
        $applicationDeploymentQueue->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
        $applicationDeploymentQueue->save();

    }
}
