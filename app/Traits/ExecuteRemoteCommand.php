<?php

namespace App\Traits;

use App\Enums\ApplicationDeploymentStatus;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

trait ExecuteRemoteCommand
{
    use SshRetryable;

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

            $maxRetries = config('constants.ssh.max_retries');
            $attempt = 0;
            $lastError = null;
            $commandExecuted = false;

            while ($attempt < $maxRetries && ! $commandExecuted) {
                try {
                    $this->executeCommandWithProcess($command, $hidden, $customType, $append, $ignore_errors);
                    $commandExecuted = true;
                } catch (\RuntimeException $e) {
                    $lastError = $e;
                    $errorMessage = $e->getMessage();
                    // Only retry if it's an SSH connection error and we haven't exhausted retries
                    if ($this->isRetryableSshError($errorMessage) && $attempt < $maxRetries - 1) {
                        $attempt++;
                        $delay = $this->calculateRetryDelay($attempt - 1);

                        // Track SSH retry event in Sentry
                        $this->trackSshRetryEvent($attempt, $maxRetries, $delay, $errorMessage, [
                            'server' => $this->server->name ?? $this->server->ip ?? 'unknown',
                            'command' => remove_iip($command),
                            'trait' => 'ExecuteRemoteCommand',
                        ]);

                        // Add log entry for the retry
                        if (isset($this->application_deployment_queue)) {
                            $this->addRetryLogEntry($attempt, $maxRetries, $delay, $errorMessage);
                        }

                        sleep($delay);
                    } else {
                        // Not retryable or max retries reached
                        throw $e;
                    }
                }
            }

            // If we exhausted all retries and still failed
            if (! $commandExecuted && $lastError) {
                throw $lastError;
            }
        });
    }

    /**
     * Execute the actual command with process handling
     */
    private function executeCommandWithProcess($command, $hidden, $customType, $append, $ignore_errors)
    {
        $remote_command = SshMultiplexingHelper::generateSshCommand($this->server, $command);
        $process = Process::timeout(3600)->idleTimeout(3600)->start($remote_command, function (string $type, string $output) use ($command, $hidden, $customType, $append) {
            $output = str($output)->trim();
            if ($output->startsWith('╔')) {
                $output = "\n".$output;
            }

            // Sanitize output to ensure valid UTF-8 encoding before JSON encoding
            $sanitized_output = sanitize_utf8_text($output);

            $new_log_entry = [
                'command' => remove_iip($command),
                'output' => remove_iip($sanitized_output),
                'type' => $customType ?? $type === 'err' ? 'stderr' : 'stdout',
                'timestamp' => Carbon::now('UTC'),
                'hidden' => $hidden,
                'batch' => static::$batch_counter,
            ];
            if (! $this->application_deployment_queue->logs) {
                $new_log_entry['order'] = 1;
            } else {
                try {
                    $previous_logs = json_decode($this->application_deployment_queue->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    // If existing logs are corrupted, start fresh
                    $previous_logs = [];
                    $new_log_entry['order'] = 1;
                }
                if (is_array($previous_logs)) {
                    $new_log_entry['order'] = count($previous_logs) + 1;
                } else {
                    $previous_logs = [];
                    $new_log_entry['order'] = 1;
                }
            }
            $previous_logs[] = $new_log_entry;

            try {
                $this->application_deployment_queue->logs = json_encode($previous_logs, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // If JSON encoding still fails, use fallback with invalid sequences replacement
                $this->application_deployment_queue->logs = json_encode($previous_logs, flags: JSON_INVALID_UTF8_SUBSTITUTE);
            }

            $this->application_deployment_queue->save();

            if ($this->save) {
                if (data_get($this->saved_outputs, $this->save, null) === null) {
                    data_set($this->saved_outputs, $this->save, str());
                }
                if ($append) {
                    $this->saved_outputs[$this->save] .= str($sanitized_output)->trim();
                    $this->saved_outputs[$this->save] = str($this->saved_outputs[$this->save]);
                } else {
                    $this->saved_outputs[$this->save] = str($sanitized_output)->trim();
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
    }

    /**
     * Add a log entry for SSH retry attempts
     */
    private function addRetryLogEntry(int $attempt, int $maxRetries, int $delay, string $errorMessage)
    {
        $retryMessage = "SSH connection failed. Retrying... (Attempt {$attempt}/{$maxRetries}, waiting {$delay}s)\nError: {$errorMessage}";

        $new_log_entry = [
            'output' => remove_iip($retryMessage),
            'type' => 'stdout',
            'timestamp' => Carbon::now('UTC'),
            'hidden' => false,
            'batch' => static::$batch_counter,
        ];

        if (! $this->application_deployment_queue->logs) {
            $new_log_entry['order'] = 1;
            $previous_logs = [];
        } else {
            try {
                $previous_logs = json_decode($this->application_deployment_queue->logs, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $previous_logs = [];
                $new_log_entry['order'] = 1;
            }
            if (is_array($previous_logs)) {
                $new_log_entry['order'] = count($previous_logs) + 1;
            } else {
                $previous_logs = [];
                $new_log_entry['order'] = 1;
            }
        }

        $previous_logs[] = $new_log_entry;

        try {
            $this->application_deployment_queue->logs = json_encode($previous_logs, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->application_deployment_queue->logs = json_encode($previous_logs, flags: JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $this->application_deployment_queue->save();
    }
}
