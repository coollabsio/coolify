<?php

namespace App\Traits;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

trait ExecuteRemoteCommand
{
    public string|null $save = null;

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

        $ip = data_get($this->server, 'ip');
        $user = data_get($this->server, 'user');
        $port = data_get($this->server, 'port');

        $commandsText->each(function ($single_command) use ($ip, $user, $port) {
            $command = data_get($single_command, 'command') ?? $single_command[0] ?? null;
            if ($command === null) {
                throw new \RuntimeException('Command is not set');
            }
            $hidden = data_get($single_command, 'hidden', false);
            $ignore_errors = data_get($single_command, 'ignore_errors', false);
            $this->save = data_get($single_command, 'save');

            $remote_command = generateSshCommand( $ip, $user, $port, $command);
            $process =  Process::timeout(3600)->idleTimeout(3600)->start($remote_command, function (string $type, string $output) use ($command, $hidden) {
                $output = Str::of($output)->trim();
                $new_log_entry = [
                    'command' => $command,
                    'output' => $output,
                    'type' => $type === 'err' ? 'stderr' : 'stdout',
                    'timestamp' => Carbon::now('UTC'),
                    'hidden' => $hidden,
                    'batch' => static::$batch_counter,
                ];

                if (!$this->log_model->logs) {
                    $new_log_entry['order'] = 1;
                } else {
                    $previous_logs = json_decode($this->log_model->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                    $new_log_entry['order'] = count($previous_logs) + 1;
                }

                $previous_logs[] = $new_log_entry;
                $this->log_model->logs = json_encode($previous_logs, flags: JSON_THROW_ON_ERROR);
                $this->log_model->save();

                if ($this->save) {
                    $this->saved_outputs[$this->save] = Str::of($output)->trim();
                }
            });
            $this->log_model->update([
                'current_process_id' => $process->id(),
            ]);

            $process_result = $process->wait();
            if ($process_result->exitCode() !== 0) {
                if (!$ignore_errors) {
                    $status = ApplicationDeploymentStatus::FAILED->value;
                    $this->log_model->status = $status;
                    $this->log_model->save();
                    throw new \RuntimeException($process_result->errorOutput());
                }
            }
        });
    }
}
