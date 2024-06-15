<?php

//
//namespace App\Services\Remote;
//
//use App\Domain\Remote\Commands\RemoteCommand;
//use App\Enums\ApplicationDeploymentStatus;
//use App\Exceptions\RemoteCommandFailedException;
//use App\Models\ApplicationDeploymentQueue;
//use App\Models\Server;
//use App\Services\Contracts\Remote\RemoteCommandContract;
//use Carbon\Carbon;
//use Illuminate\Process\InvokedProcess;
//use Illuminate\Support\Collection;
//use Illuminate\Support\Facades\Process;
//use Illuminate\Support\Str;
//
//class RemoteCommandService implements RemoteCommandContract
//{
//    private int $batchCounter = 0;
//
//    private RemoteCommandGeneratorService $remoteCommandFactory;
//
//    private Server $server;
//
//    private ApplicationDeploymentQueue $applicationDeploymentQueue;
//
//    private Collection $savedOutputs;
//
//    public function __construct(RemoteCommandGeneratorService $remoteCommandFactory, Server $server,
//        ApplicationDeploymentQueue $applicationDeploymentQueue)
//    {
//        $this->remoteCommandFactory = $remoteCommandFactory;
//        $this->server = $server;
//        $this->applicationDeploymentQueue = $applicationDeploymentQueue;
//    }
//
//    /**
//     * Execute a remote command.
//     *
//     * @param  array  $commands  The commands to execute.
//     */
//    public function executeRemoteCommand(array $commands): void
//    {
//        $this->batchCounter++;
//
//        $commandsToExecute = collect($commands);
//
//
//        $commandsToExecute->each(function (RemoteCommand $command) {
//            $this->executeCommand($command);
//        });
//    }
//
//
//    private function executeCommand(RemoteCommand $command): void
//    {
//        $remoteCommand = $this->remoteCommandFactory->create($this->server, $command->command);
//
//        $process = $this->createProcess($remoteCommand, $command);
//
//        $this->applicationDeploymentQueue->update([
//            'current_process_id' => $process->id(),
//        ]);
//
//        $processResult = $process->wait();
//
//        if ($processResult->exitCode() !== 0 && ! $command->ignoreErrors) {
//            $this->applicationDeploymentQueue->status = ApplicationDeploymentStatus::FAILED->value;
//            $this->applicationDeploymentQueue->save();
//
//            throw new RemoteCommandFailedException($processResult->errorOutput());
//        }
//
//    }
//
//    public function createProcess(string $remoteCommand, RemoteCommand $command): InvokedProcess
//    {
//        $process = Process::timeout(3600)->idleTimeout(3600)->start($remoteCommand, function (string $type, string $output) use ($command) {
//            $output = Str::of($output)->trim();
//            if ($output->startsWith('╔')) {
//                $output = "\n".$output;
//            }
//            $new_log_entry = [
//                'command' => remove_iip($command),
//                'output' => remove_iip($output),
//                'type' => $customType ?? $type === 'err' ? 'stderr' : 'stdout',
//                'timestamp' => Carbon::now('UTC'),
//                'hidden' => $command->hidden,
//                'batch' => $this->batchCounter,
//                'order' => 1,
//            ];
//            if ($this->applicationDeploymentQueue->logs) {
//                $previous_logs = json_decode($this->applicationDeploymentQueue->logs, associative: true, flags: JSON_THROW_ON_ERROR);
//                $new_log_entry['order'] = count($previous_logs) + 1;
//            }
//            $previous_logs[] = $new_log_entry;
//            $this->applicationDeploymentQueue->logs = json_encode($previous_logs, flags: JSON_THROW_ON_ERROR);
//            $this->applicationDeploymentQueue->save();
//
//            if ($command->shouldSave()) {
//                if (data_get($this->savedOutputs, $command->save, null) === null) {
//                    data_set($this->savedOutputs, $command->save, str());
//                }
//                if ($command->append) {
//                    $this->savedOutputs[$command->save] .= str($output)->trim();
//                    // This line is redundant, it is always a string now.
//                    //$this->savedOutputs[$command->save] = str($this->savedOutputs[$command->save]);
//                } else {
//                    $this->savedOutputs[$command->save] = str($output)->trim();
//                }
//            }
//        });
//
//        return $process;
//    }
//}
