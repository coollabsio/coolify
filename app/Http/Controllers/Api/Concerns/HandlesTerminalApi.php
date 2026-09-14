<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Server;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process as SymfonyProcess;

trait HandlesTerminalApi
{
    private const TERMINAL_SERVER_RATE_LIMIT = 20;

    private const TERMINAL_SERVER_CONCURRENCY_LIMIT = 3;

    private const TERMINAL_COMMAND_TIMEOUT = 10;

    private const TERMINAL_PROCESS_TIMEOUT_GRACE = 5;

    private const TERMINAL_COMMAND_OUTPUT_LIMIT = 65536;

    private function enforceTerminalServerRateLimit(Server $server, int $teamId): ?JsonResponse
    {
        $key = "terminal-api-exec:server:{$teamId}:{$server->uuid}";

        if (RateLimiter::tooManyAttempts($key, self::TERMINAL_SERVER_RATE_LIMIT)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Too many terminal commands for this server. Please retry in {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429, ['Retry-After' => $retryAfter]);
        }

        RateLimiter::hit($key, 60);

        return null;
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

    private function runTerminalProcess(string $command, int $timeout): ProcessResult
    {
        $output = '';
        $errorOutput = '';
        $captureLimit = self::TERMINAL_COMMAND_OUTPUT_LIMIT + 1;
        $process = Process::timeout($this->terminalProcessTimeout($timeout))->start(
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

    private function runConcurrentTerminalProcess(Server $server, int $teamId, string $command, int $timeout): ProcessResult|JsonResponse
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
            return $this->runTerminalProcess($command, $timeout);
        } finally {
            $lock->release();
        }
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
