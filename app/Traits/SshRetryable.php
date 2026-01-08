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
            'Host key verification failed',
            'Operation timed out',
            'Connection closed unexpectedly',
            'Remote host closed connection',
            'Authentication failed',
            'Too many authentication failures',
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
     * @return mixed The result from the callback
     */
    protected function executeWithSshRetry(callable $callback, array $context = [], bool $throwError = true)
    {
        $maxRetries = config('constants.ssh.max_retries');
        $lastError = null;
        $lastErrorMessage = '';
        // Randomly fail the command with a key exchange error for testing
        // if (random_int(1, 10) === 1) { // 10% chance to fail
        //     ray('SSH key exchange failed: kex_exchange_identification: read: Connection reset by peer');
        //     throw new \RuntimeException('SSH key exchange failed: kex_exchange_identification: read: Connection reset by peer');
        // }
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastError = $e;
                $lastErrorMessage = $e->getMessage();

                // Check if it's retryable and not the last attempt
                if ($this->isRetryableSshError($lastErrorMessage) && $attempt < $maxRetries - 1) {
                    $delay = $this->calculateRetryDelay($attempt);

                    // Track SSH retry event in Sentry
                    $this->trackSshRetryEvent($attempt + 1, $maxRetries, $delay, $lastErrorMessage, $context);

                    // Add deployment log if available (for ExecuteRemoteCommand trait)
                    if (isset($this->application_deployment_queue) && method_exists($this, 'addRetryLogEntry')) {
                        $this->addRetryLogEntry($attempt + 1, $maxRetries, $delay, $lastErrorMessage);
                    }

                    sleep($delay);

                    continue;
                }

                // Not retryable or max retries reached
                break;
            }
        }

        // All retries exhausted
        if ($attempt >= $maxRetries) {
            Log::error('SSH operation failed after all retries', array_merge($context, [
                'attempts' => $attempt,
                'error' => $lastErrorMessage,
            ]));
        }

        if ($throwError && $lastError) {
            // If the error message is empty, provide a more meaningful one
            if (empty($lastErrorMessage) || trim($lastErrorMessage) === '') {
                $contextInfo = isset($context['server']) ? " to server {$context['server']}" : '';
                $attemptInfo = $attempt > 1 ? " after {$attempt} attempts" : '';
                throw new \RuntimeException("SSH connection failed{$contextInfo}{$attemptInfo}", $lastError->getCode());
            }
            throw $lastError;
        }

        return null;
    }

    /**
     * Track SSH retry event in Sentry
     */
    protected function trackSshRetryEvent(int $attempt, int $maxRetries, int $delay, string $errorMessage, array $context = []): void
    {
        // Only track in production/cloud instances
        if (isDev() || ! config('constants.sentry.sentry_dsn')) {
            return;
        }

        try {
            app('sentry')->captureMessage(
                'SSH connection retry triggered',
                \Sentry\Severity::warning(),
                [
                    'extra' => [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'delay_seconds' => $delay,
                        'error_message' => $errorMessage,
                        'context' => $context,
                        'retryable_error' => true,
                    ],
                    'tags' => [
                        'component' => 'ssh_retry',
                        'error_type' => 'connection_retry',
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // Don't let Sentry tracking errors break the SSH retry flow
            Log::warning('Failed to track SSH retry event in Sentry', [
                'error' => $e->getMessage(),
                'original_attempt' => $attempt,
            ]);
        }
    }
}
