<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process as SymfonyProcess;

trait HandlesTerminalApi
{
    private const TERMINAL_SERVER_RATE_LIMIT = 60;

    private const TERMINAL_SERVER_CONCURRENCY_LIMIT = 3;

    private const TERMINAL_COMMAND_TIMEOUT = 10;

    private const TERMINAL_PROCESS_TIMEOUT_GRACE = 5;

    private const TERMINAL_COMMAND_OUTPUT_LIMIT = 65536;

    /**
     * The web terminal uses the same checks (Project\Shared\Terminal::sendTerminalCommand).
     */
    private function isTerminalDisabledOn(Server $server): bool
    {
        return ! $server->isTerminalEnabled() || $server->isForceDisabled() || $server->isTransferredAway();
    }

    private function enforceTerminalServerRateLimit(Server $server, int $teamId): ?JsonResponse
    {
        $key = "terminal-api-exec:server:{$teamId}:{$server->uuid}";

        // Count first: hit() increments atomically, so parallel requests cannot all pass the check.
        if (RateLimiter::hit($key, 60) > self::TERMINAL_SERVER_RATE_LIMIT) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Too many terminal commands for this server. Please retry in {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429, ['Retry-After' => $retryAfter]);
        }

        return null;
    }

    /**
     * Wraps the command so the target stops it after the timeout. The local timeout stops only the
     * SSH client: without a TTY, the remote process and a `docker exec` process keep running.
     * A target without a working `timeout` (a minimal image, an old BusyBox) runs the command without it.
     * BusyBox `timeout` (Alpine images) exits with 143 instead of 124; it fails on `--version`, GNU `timeout` does not.
     *
     * @param  string  $shell  Shell expression that runs the command, for example `sh`.
     */
    private function withRemoteTimeout(string $command, int $timeout, string $shell = 'sh'): string
    {
        $script = "s={$shell}; if timeout 1 true >/dev/null 2>&1; then timeout {$timeout} \"\$s\" -c \"\$1\"; r=\$?; "
            .'if [ "$r" = 143 ] && ! timeout --version >/dev/null 2>&1; then r=124; fi; exit "$r"; fi; '
            ."exec \"\$s\" -c \"\$1\"";

        return 'sh -c '.escapeshellarg($script).' sh '.escapeshellarg($command);
    }

    private function terminalProcessTimeout(int $commandTimeout): int
    {
        return $commandTimeout + self::TERMINAL_PROCESS_TIMEOUT_GRACE;
    }

    private function terminalTimedOutResponse(int $timeout): JsonResponse
    {
        return response()->json([
            'exit_code' => 124,
            'stdout' => '',
            'stderr' => "Command timed out after {$timeout} seconds.",
        ]);
    }

    private function runTerminalProcess(string $command, int $timeout, ?string $input = null): ProcessResult
    {
        $output = '';
        $errorOutput = '';
        $captureLimit = self::TERMINAL_COMMAND_OUTPUT_LIMIT + 1;
        $process = Process::timeout($this->terminalProcessTimeout($timeout))->input($input)->start(
            $command,
            function (string $type, string $chunk) use (&$output, &$errorOutput, $captureLimit): void {
                $buffer = $type === SymfonyProcess::OUT ? $output : $errorOutput;
                $remaining = $captureLimit - strlen($buffer);

                if ($remaining > 0) {
                    $buffer .= substr($chunk, 0, $remaining);
                }

                if ($type === SymfonyProcess::OUT) {
                    $output = $buffer;
                } else {
                    $errorOutput = $buffer;
                }
            },
        );

        return new TerminalProcessResult($process->wait(), $output, $errorOutput);
    }

    /**
     * Runs the callback in one of the concurrency slots of the server, or returns a 429 response when every slot is taken.
     * Every SSH connection of a terminal API request to the server must run in a slot.
     *
     * @template TResult
     *
     * @param  int  $timeout  Command timeout in seconds; the slot expires shortly after the process timeout.
     * @param  Closure(): TResult  $callback
     * @return TResult|JsonResponse
     */
    private function runInTerminalServerSlot(Server $server, int $teamId, int $timeout, Closure $callback): mixed
    {
        $key = "terminal-api-exec:concurrent:team:{$teamId}:server:{$server->uuid}:";

        $lock = collect(range(1, self::TERMINAL_SERVER_CONCURRENCY_LIMIT))
            ->map(fn (int $slot) => Cache::lock($key.$slot, $this->terminalProcessTimeout($timeout) + 1))
            ->first(fn ($lock) => $lock->acquire());

        if ($lock === null) {
            return response()->json([
                'message' => 'Too many terminal commands are already running on this server. Please retry shortly.',
                'retry_after' => 1,
            ], 429, ['Retry-After' => 1]);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function runConcurrentTerminalProcess(Server $server, int $teamId, string $remoteCommand, int $timeout): ProcessResult|JsonResponse
    {
        return $this->runInTerminalServerSlot($server, $teamId, $timeout, function () use ($server, $remoteCommand, $timeout) {
            // Build the SSH command only inside a slot: it can open an SSH connection to scan the host key.
            // The SSH timeout ends after the process timeout: the remote timeout must stop the command first
            // (exit code 124), and BusyBox `timeout` returns 255 for a stopped SSH client.
            // The command goes on stdin, so it is not on a command line of the Coolify host.
            $sshCommand = SshMultiplexingHelper::generateSshBatchCommand($server, disableMultiplexing: true, commandTimeout: $this->terminalProcessTimeout($timeout) + 1);

            return $this->runTerminalProcess($sshCommand, $timeout, SshMultiplexingHelper::sshBatchInput($remoteCommand));
        });
    }

    private function formatTerminalCommandOutput(string $output): string
    {
        $output = rtrim(sanitize_utf8_text($output), "\r\n");
        $truncationMarker = "\n[... Output truncated at ".self::TERMINAL_COMMAND_OUTPUT_LIMIT.' bytes ...]';

        if (strlen($output) <= self::TERMINAL_COMMAND_OUTPUT_LIMIT) {
            return $output;
        }

        return mb_strcut($output, 0, self::TERMINAL_COMMAND_OUTPUT_LIMIT - strlen($truncationMarker), 'UTF-8').$truncationMarker;
    }
}
