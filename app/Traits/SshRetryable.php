<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait SshRetryable
{
    /**
     * Check if an error message indicates a retryable SSH connection error
     */
    protected function isRetryableSshError(string $errorOutput): bool
    {
        $retryablePatterns = [
            'kex_exchange_identification',
            'Connection reset by peer',
            'Connection refused',
            'Connection timed out',
            'Connection closed by remote host',
            'ssh_exchange_identification',
            'Bad file descriptor',
            'Broken pipe',
            'No route to host',
            'Network is unreachable',
            'Host is down',
            'No buffer space available',
            'Connection reset by',
            'Permission denied, please try again',
            'Received disconnect from',
            'Disconnected from',
            'Connection to .* closed',
            'ssh: connect to host .* port .*: Connection',
            'Lost connection',
            'Timeout, server not responding',
            'Cannot assign requested address',
            'Network is down',
            'Operation timed out',
            'Connection closed unexpectedly',
            'Remote host closed connection',
            'Authentication failed',
            'Too many authentication failures',
            'SSH command failed with exit code: 255',
        ];

        $lowerErrorOutput = strtolower($errorOutput);
        foreach ($retryablePatterns as $pattern) {
            if (str_contains($lowerErrorOutput, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a failure came from the SSH connection and not from the remote command.
     *
     * SSH exits with 255 for its own errors. Any other non-zero exit code comes from the
     * remote command, whose output can contain words such as "Connection refused" (for
     * example a failed S3 upload). Retrying would replay commands that already ran.
     */
    protected function isRetryableSshFailure(\Throwable $exception): bool
    {
        $exitCode = $exception->getCode();
        if (is_int($exitCode) && $exitCode > 0 && $exitCode !== 255) {
            return false;
        }

        return $this->isRetryableSshError($exception->getMessage());
    }

    /**
     * sshd closes a new connection before the key exchange when it has too many unauthenticated
     * connections (MaxStartups, often bots on a public port 22) or when it blocks the source address
     * for a time (PerSourcePenalties). This can last longer than the normal retries. No session
     * existed, so the remote command did not run and a retry cannot run it two times.
     */
    protected function sshAttemptLimit(\Throwable $exception, int $maxAttempts): int
    {
        $message = $exception->getMessage();
        if (! str_contains($message, 'kex_exchange_identification') && ! str_contains($message, 'ssh_exchange_identification')) {
            return $maxAttempts;
        }

        return max($maxAttempts, (int) config('constants.ssh.handshake_max_retries'));
    }

    /**
     * Calculate delay for exponential backoff
     */
    protected function calculateRetryDelay(int $attempt): int
    {
        $baseDelay = config('constants.ssh.retry_base_delay');
        $maxDelay = config('constants.ssh.retry_max_delay');
        $multiplier = config('constants.ssh.retry_multiplier');

        $delay = min($baseDelay * pow($multiplier, $attempt), $maxDelay);

        return (int) $delay;
    }

    /**
     * Execute a callback with SSH retry logic
     *
     * @param  callable  $callback  The operation to execute
     * @param  array  $context  Context for logging (server, command, etc.)
     * @param  bool  $throwError  Whether to throw error on final failure
     * @param  int|null  $maxAttempts  Attempts in total, defaults to constants.ssh.max_retries (constants.ssh.handshake_max_retries when sshd refused the handshake)
     * @return mixed The result from the callback
     */
    protected function executeWithSshRetry(callable $callback, array $context = [], bool $throwError = true, ?int $maxAttempts = null)
    {
        $maxRetries = $maxAttempts ?? config('constants.ssh.max_retries');
        $lastError = null;
        $lastErrorMessage = '';
        // Randomly fail the command with a key exchange error for testing
        // if (random_int(1, 10) === 1) { // 10% chance to fail
        //     throw new \RuntimeException('SSH key exchange failed: kex_exchange_identification: read: Connection reset by peer');
        // }
        for ($attempt = 0; ; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastError = $e;
                $lastErrorMessage = $e->getMessage();
                $attemptLimit = $maxAttempts ?? $this->sshAttemptLimit($e, $maxRetries);

                // Check if it's retryable and not the last attempt
                if ($this->isRetryableSshFailure($e) && $attempt < $attemptLimit - 1) {
                    $delay = $this->calculateRetryDelay($attempt);

                    // Add deployment log if available (for ExecuteRemoteCommand trait)
                    if (isset($this->application_deployment_queue) && method_exists($this, 'addRetryLogEntry')) {
                        $this->addRetryLogEntry($attempt + 1, $attemptLimit, $delay, $lastErrorMessage);
                    }

                    sleep($delay);

                    continue;
                }

                // Not retryable or max retries reached
                break;
            }
        }

        // All retries exhausted
        if ($attempt > 0) {
            Log::error('SSH operation failed after all retries', array_merge($context, [
                'attempts' => $attempt + 1,
                'error' => $lastErrorMessage,
            ]));
        }

        if ($throwError && $lastError) {
            // If the error message is empty, provide a more meaningful one
            if (empty($lastErrorMessage) || trim($lastErrorMessage) === '') {
                $contextInfo = isset($context['server']) ? " to server {$context['server']}" : '';
                $attemptInfo = $attempt > 0 ? ' after '.($attempt + 1).' attempts' : '';
                throw new \RuntimeException("SSH connection failed{$contextInfo}{$attemptInfo}", $lastError->getCode());
            }
            throw $lastError;
        }

        return null;
    }
}
