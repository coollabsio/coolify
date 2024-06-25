<?php

namespace App\Traits;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

trait ExecuteRemoteCommand
{
    public ?string $save = null;

    public static int $batch_counter = 0;

    public function execute_remote_command(...$commands)
    {
        static::$batch_counter++;
        if ($commands instanceof Collection) {
            $commandsText = $commands;
        } else {
            $commandsText = collect($commands);
        }
        if ($this->server instanceof Server === false) {
            throw new \RuntimeException('Server is not set or is not an instance of Server model');
        }
        $commandsText->each(function ($single_command) {
            $command = data_get($single_command, 'command') ?? $single_command[0] ?? null;
            if ($command === null) {
                throw new \RuntimeException('Command is not set');
            }
            $hidden = data_get($single_command, 'hidden', false);
            $customType = data_get($single_command, 'type');
            $ignore_errors = data_get($single_command, 'ignore_errors', false);
            $append = data_get($single_command, 'append', true);
            $this->save = data_get($single_command, 'save');
            if ($this->server->isNonRoot()) {
                if (str($command)->startsWith('docker exec')) {
                    $command = str($command)->replace('docker exec', 'sudo docker exec');
                } else {
                    $command = parseLineForSudo($command, $this->server);
                }
            }
            $remote_command = generateSshCommand($this->server, $command);
            $process = Process::timeout(3600)->idleTimeout(3600)->start($remote_command, function (string $type, string $output) use ($command, $hidden, $customType, $append) {
                $output = str($output)->trim();
                if ($output->startsWith('╔')) {
                    $output = "\n".$output;
                }
                $new_log_entry = [
                    'command' => remove_iip($command),
                    'output' => remove_iip($output),
                    'type' => $customType ?? $type === 'err' ? 'stderr' : 'stdout',
                    'timestamp' => Carbon::now('UTC'),
                    'hidden' => $hidden,
                    'batch' => static::$batch_counter,
                ];
                if (! $this->application_deployment_queue->logs) {
                    $new_log_entry['order'] = 1;
                } else {
                    $previous_logs = json_decode($this->application_deployment_queue->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                    $new_log_entry['order'] = count($previous_logs) + 1;
                }
                $previous_logs[] = $new_log_entry;
                $this->application_deployment_queue->logs = json_encode($previous_logs, flags: JSON_THROW_ON_ERROR);
                $this->application_deployment_queue->save();

                if ($this->save) {
                    if (data_get($this->saved_outputs, $this->save, null) === null) {
                        data_set($this->saved_outputs, $this->save, str());
                    }
                    if ($append) {
                        $this->saved_outputs[$this->save] .= str($output)->trim();
                        $this->saved_outputs[$this->save] = str($this->saved_outputs[$this->save]);
                    } else {
                        $this->saved_outputs[$this->save] = str($output)->trim();
                    }
                }
            });
            $this->application_deployment_queue->update([
                'current_process_id' => $process->id(),
            ]);

            $process_result = $process->wait();
            if ($process_result->exitCode() !== 0) {
                if (! $ignore_errors) {
                    $this->application_deployment_queue->status = ApplicationDeploymentStatus::FAILED->value;
                    $this->application_deployment_queue->save();
                    throw new \RuntimeException($process_result->errorOutput());
                }
            }
        });
    }
}
