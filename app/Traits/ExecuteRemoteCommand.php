<?php

namespace App\Traits;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Helpers\SshMultiplexingHelper;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Services\Security\SensitiveDataRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Stringable;

trait ExecuteRemoteCommand
{
    use SshRetryable;

    private const SENSITIVE_COMMAND_PLACEHOLDER = '[command hidden because it contains sensitive data]';

    public ?string $save = null;

    public static int $batch_counter = 0;

    /** @var array{known: array<int, string>, locked: array<int, string>}|null */
    private ?array $logRedactionSecrets = null;

    /** @var array<int, string>|null */
    private ?array $remoteSecretLogRedactionValues = null;

    private function redact_sensitive_info(string $text): string
    {
        try {
            // Remote secrets come from the job's remote_secrets_cache below, so they are not fetched again.
            $this->logRedactionSecrets ??= EnvironmentVariable::logRedactionSecretsFor(
                $this->application ?? null,
                isset($this->pull_request_id) && $this->pull_request_id !== 0,
                resolveRemoteSecrets: false,
            );
        } catch (\Throwable) {
            return REDACTED;
        }

        $remoteSecrets = isset($this->remote_secrets_cache)
            ? ($this->remoteSecretLogRedactionValues ??= EnvironmentVariable::remoteSecretLogRedactionValues($this->remote_secrets_cache))
            : [];

        return app(SensitiveDataRedactor::class)->redactText(
            $text,
            $this->logRedactionSecrets['known'],
            array_merge($this->logRedactionSecrets['locked'], $remoteSecrets),
        );
    }

    /**
     * Remote secrets are never stored in Coolify, so the deployment log model must get them from the job.
     */
    private function registerRemoteSecretsForLogs(): void
    {
        if (isset($this->application_deployment_queue, $this->remote_secrets_cache)) {
            $this->application_deployment_queue->redactRemoteSecrets($this->remote_secrets_cache);
        }
    }

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
        $this->registerRemoteSecretsForLogs();
        $commandsText->each(function ($single_command) {
            $command = data_get($single_command, 'command') ?? $single_command[0] ?? null;
            if ($command === null) {
                throw new \RuntimeException('Command is not set');
            }
            $hidden = data_get($single_command, 'hidden', false);
            $customType = data_get($single_command, 'type');
            $ignore_errors = data_get($single_command, 'ignore_errors', false);
            $append = data_get($single_command, 'append', true);
            $command_hidden = data_get($single_command, 'command_hidden', false);
            $skip_command_log = data_get($single_command, 'skip_command_log', false);
            $input = data_get($single_command, 'input');
            $this->save = data_get($single_command, 'save');
            if ($this->server->isNonRoot()) {
                if (str($command)->startsWith('docker exec')) {
                    $command = str($command)->replace('docker exec', 'sudo docker exec');
                } else {
                    $command = parseLineForSudo($command, $this->server);
                }
            }

            // Check for cancellation before executing commands
            if (isset($this->application_deployment_queue)) {
                $this->application_deployment_queue->refresh();
                if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
                    throw new \RuntimeException('Deployment cancelled by user', 69420);
                }
            }

            $maxRetries = config('constants.ssh.max_retries');
            $attempt = 0;
            $lastError = null;
            $commandExecuted = false;

            while ($attempt < $maxRetries && ! $commandExecuted) {
                try {
                    $this->executeCommandWithProcess($command, $hidden, $customType, $append, $ignore_errors, $command_hidden, $skip_command_log, $input);
                    $commandExecuted = true;
                } catch (\RuntimeException|DeploymentException $e) {
                    $lastError = $e;
                    $errorMessage = $e->getMessage();
                    // Only retry if it's an SSH connection error and we haven't exhausted retries
                    if ($this->isRetryableSshFailure($e) && $attempt < $maxRetries - 1) {
                        $attempt++;
                        $delay = $this->calculateRetryDelay($attempt - 1);

                        // Add log entry for the retry
                        if (isset($this->application_deployment_queue)) {
                            $this->addRetryLogEntry($attempt, $maxRetries, $delay, $errorMessage);

                            // Check for cancellation during retry wait
                            $this->application_deployment_queue->refresh();
                            if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
                                throw new \RuntimeException('Deployment cancelled by user during retry', 69420);
                            }
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
                // Now we can set the status to FAILED since all retries have been exhausted
                // But only if the deployment hasn't already been marked as FINISHED
                if (isset($this->application_deployment_queue)) {
                    // Avoid clobbering a deployment that may have just been marked FINISHED
                    $this->application_deployment_queue->newQuery()
                        ->where('id', $this->application_deployment_queue->id)
                        ->where('status', '!=', ApplicationDeploymentStatus::FINISHED->value)
                        ->update([
                            'status' => ApplicationDeploymentStatus::FAILED->value,
                        ]);
                }
                throw $lastError;
            }
        });
    }

    /**
     * Execute the actual command with process handling. With $input, the content goes to the command over
     * SSH stdin, because Linux limits one exec argument to 128 KiB and generateSshCommand() puts the whole
     * script into one argument.
     */
    private function executeCommandWithProcess($command, $hidden, $customType, $append, $ignore_errors, $command_hidden = false, $skip_command_log = false, ?string $input = null)
    {
        if ($command_hidden && ! $skip_command_log && isset($this->application_deployment_queue)) {
            $this->application_deployment_queue->addLogEntry('[CMD]: '.$this->redact_sensitive_info($command), hidden: true);
        }

        $remote_command = $input === null
            ? SshMultiplexingHelper::generateSshCommand($this->server, $command)
            : SshMultiplexingHelper::generateSshStdinCommand($this->server, $command);
        $addLogEntry = function (string $type, string $output) use ($command, $hidden, $customType, $command_hidden, $skip_command_log): void {
            // Sanitize output to ensure valid UTF-8 encoding before JSON encoding
            $log_output = str(sanitize_utf8_text($output))->trim();
            if ($log_output->startsWith('╔')) {
                $log_output = "\n".$log_output;
            }

            $this->application_deployment_queue->addLogEntry(
                message: $skip_command_log ? $this->redactSensitiveCommandPayloads((string) $log_output, (string) $command) : (string) $log_output,
                type: $customType ?? ($type === 'err' ? 'stderr' : 'stdout'),
                hidden: $hidden,
                command: $skip_command_log || $command_hidden ? null : $command,
                batch: static::$batch_counter,
            );
        };
        // Output is logged per complete line, so a secret split across two output chunks is still redacted.
        $pending = [];
        $process = Process::timeout(config('constants.ssh.command_timeout'))->idleTimeout(3600)->input($input)->start($remote_command, function (string $type, string $output) use ($addLogEntry, $append, &$pending) {
            $pending[$type] = ($pending[$type] ?? '').$output;
            $complete_output = RunRemoteProcess::takeCompleteOutput($pending[$type]);
            if ($complete_output !== '') {
                $addLogEntry($type, $complete_output);
            }

            $this->saveCommandOutput(sanitize_utf8_text($output), $append);
        });
        $this->application_deployment_queue->update([
            'current_process_id' => $process->id(),
        ]);

        try {
            $process_result = $process->wait();
        } finally {
            foreach ($pending as $type => $output) {
                $remaining_output = RunRemoteProcess::takeCompleteOutput($pending[$type], final: true);
                if ($remaining_output !== '') {
                    $addLogEntry($type, $remaining_output);
                }
            }
        }
        if ($process_result->exitCode() !== 0) {
            if (! $ignore_errors) {
                // Check if deployment was cancelled while command was running
                if (isset($this->application_deployment_queue)) {
                    $this->application_deployment_queue->refresh();
                    if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
                        throw new \RuntimeException('Deployment cancelled by user', 69420);
                    }
                }

                // Don't immediately set to FAILED - let the retry logic handle it
                // This prevents premature status changes during retryable SSH errors
                $error = $process_result->errorOutput();
                if (empty($error)) {
                    $error = $process_result->output() ?: 'Command failed with no error output';
                }
                throw new DeploymentException($this->commandFailureMessage((string) $command, (int) $process_result->exitCode(), (string) $error, $skip_command_log), (int) $process_result->exitCode());
            }
        }
    }

    /**
     * Commands marked with skip_command_log embed secrets (for example base64 encoded .env files or
     * private keys), so their text must never reach a log line or an exception message.
     */
    private function commandFailureMessage(string $command, int $exitCode, string $error, bool $isSensitiveCommand): string
    {
        if ($isSensitiveCommand) {
            $commandText = self::SENSITIVE_COMMAND_PLACEHOLDER;
            $error = $this->redactSensitiveCommandPayloads($error, $command);
        } else {
            $commandText = $this->redact_sensitive_info($command);
        }

        $error = $this->redact_sensitive_info($error);

        return "Command execution failed (exit code {$exitCode}): {$commandText}\nError: {$error}".$this->dockerNetworkGatewayHint($error);
    }

    /**
     * Docker Engine 27.0 to 28.0.0 reports an IPv6 network gateway with its prefix length (fd00::1/64) until the
     * daemon restarts, and newer Docker CLI and Compose versions fail to parse it.
     *
     * @see https://github.com/moby/moby/pull/49520
     */
    private function dockerNetworkGatewayHint(string $error): string
    {
        if (! preg_match('~ParseAddr\("[0-9A-Fa-f:.]+/\d+"\)~', $error)) {
            return '';
        }

        return "\nDocker Engine on this server reports a Docker network gateway with a prefix length, which is a Docker Engine bug fixed in 28.0.1. Run 'systemctl restart docker' on the server (this restarts all containers) or upgrade Docker Engine, then deploy again.";
    }

    /**
     * Removes the encoded payloads of a sensitive command from output that could echo the command.
     */
    private function redactSensitiveCommandPayloads(string $text, string $command): string
    {
        if ($text === '' || preg_match_all('~[A-Za-z0-9+/]{16,}={0,2}~', $command, $matches) === false) {
            return $text;
        }

        $payloads = collect($matches[0])
            ->unique()
            ->filter(function (string $candidate): bool {
                $decoded = base64_decode($candidate, true);

                return $decoded !== false
                    && mb_check_encoding($decoded, 'UTF-8')
                    && preg_match('/[^\P{C}\t\n\r]/u', $decoded) !== 1;
            })
            ->sortByDesc(fn (string $payload): int => strlen($payload))
            ->values()
            ->all();

        return $payloads === [] ? $text : str_replace($payloads, REDACTED, $text);
    }

    private function saveCommandOutput(string $output, bool $append): void
    {
        if (! $this->save) {
            return;
        }

        if ($append) {
            $currentValue = $this->saved_outputs->get($this->save, '');
            $this->saved_outputs->put($this->save, str($currentValue.$output));

            return;
        }

        $this->saved_outputs->put($this->save, str($output)->trim());
    }

    private function trimmedSavedOutput(string $key): Stringable
    {
        return str($this->saved_outputs->get($key))->trim();
    }

    /**
     * Add a log entry for SSH retry attempts
     */
    private function addRetryLogEntry(int $attempt, int $maxRetries, int $delay, string $errorMessage)
    {
        $retryMessage = "SSH connection failed. Retrying... (Attempt {$attempt}/{$maxRetries}, waiting {$delay}s)\nError: {$errorMessage}";

        $this->registerRemoteSecretsForLogs();
        $this->application_deployment_queue->addLogEntry(
            message: $retryMessage,
            batch: static::$batch_counter,
        );
    }
}
