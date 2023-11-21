<?php

namespace App\Traits;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

trait ExecuteRemoteCommandNew
{
    public static $batch_counter = 0;
    public function executeRemoteCommand(Server $server, $logModel, $commands)
    {
        static::$batch_counter++;
        if ($commands instanceof Collection) {
            $commandsText = $commands;
        } else {
            $commandsText = collect($commands);
        }
        $commandsText->each(function ($singleCommand) use ($server, $logModel) {
            $command = data_get($singleCommand, 'command') ?? $singleCommand[0] ?? null;
            if ($command === null) {
                throw new \RuntimeException('Command is not set');
            }
            $hidden = data_get($singleCommand, 'hidden', false);
            $customType = data_get($singleCommand, 'type');
            $ignoreErrors = data_get($singleCommand, 'ignore_errors', false);
            $save = data_get($singleCommand, 'save');

            $remote_command = generateSshCommand($server, $command);
            $process = Process::timeout(3600)->idleTimeout(3600)->start($remote_command, function (string $type, string $output) use ($command, $hidden, $customType, $logModel, $save) {
                $output = str($output)->trim();
                if ($output->startsWith('╔')) {
                    $output = "\n" . $output;
                }
                $newLogEntry = [
                    'command' => remove_iip($command),
                    'output' => remove_iip($output),
                    'type' => $customType ?? $type === 'err' ? 'stderr' : 'stdout',
                    'timestamp' => Carbon::now('UTC'),
                    'hidden' => $hidden,
                    'batch' => static::$batch_counter,
                ];

                if (!$logModel->logs) {
                    $newLogEntry['order'] = 1;
                } else {
                    $previousLogs = json_decode($logModel->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                    $newLogEntry['order'] = count($previousLogs) + 1;
                }

                $previousLogs[] = $newLogEntry;
                $logModel->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
                $logModel->save();

                if ($save) {
                    $this->remoteCommandOutputs[$save] = str($output)->trim();
                }
            });
            $logModel->update([
                'current_process_id' => $process->id(),
            ]);

            $processResult = $process->wait();
            if ($processResult->exitCode() !== 0) {
                if (!$ignoreErrors) {
                    $status = ApplicationDeploymentStatus::FAILED->value;
                    $logModel->status = $status;
                    $logModel->save();
                    throw new \RuntimeException($processResult->errorOutput());
                }
            }
        });
    }
}
