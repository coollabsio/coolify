<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Server;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;

trait HandlesTerminalApi
{
    private const TERMINAL_SERVER_RATE_LIMIT = 60;

    private const TERMINAL_SERVER_CONCURRENCY_LIMIT = 3;

    private const TERMINAL_COMMAND_TIMEOUT = 10;

    private const TERMINAL_PROCESS_TIMEOUT_GRACE = 5;

    private const TERMINAL_COMMAND_OUTPUT_LIMIT = 65536;

    private function enforceTerminalServerRateLimit(Server $server, int $teamId): ?JsonResponse
    {
        $key = "terminal-api-exec:server:{$teamId}:{$server->uuid}";

        if (RateLimiter::tooManyAttempts($key, self::TERMINAL_SERVER_RATE_LIMIT)) {
            return response()->json([
                'message' => 'Too many terminal commands for this server. Please retry in '.RateLimiter::availableIn($key).' seconds.',
            ], 429);
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
            'stderr' => "Command timed out after {$timeout} seconds.\n",
        ]);
    }

    private function runTerminalProcess(string $command, int $timeout): ProcessResult
    {
        $process = Process::timeout($this->terminalProcessTimeout($timeout))->start($command);

        return $process->wait();
    }

    private function runConcurrentTerminalProcess(Server $server, int $teamId, string $command, int $timeout): ProcessResult|JsonResponse
    {
        $key = "terminal-api-exec:concurrent:team:{$teamId}:server:{$server->uuid}:";

        return Cache::funnel($key)
            ->limit(self::TERMINAL_SERVER_CONCURRENCY_LIMIT)
            ->releaseAfter($this->terminalProcessTimeout($timeout) + 1)
            ->block(0)
            ->then(fn () => $this->runTerminalProcess($command, $timeout), fn () => response()->json([
                'message' => 'Too many terminal commands are already running on this server. Please retry shortly.',
                'retry_after' => 1,
            ], 429, ['Retry-After' => 1]));
    }

    private function formatTerminalCommandOutput(string $output): string
    {
        $output = sanitize_utf8_text($output);
        $truncationMarker = "\n[... Output truncated at ".self::TERMINAL_COMMAND_OUTPUT_LIMIT.' bytes ...]';

        if (strlen($output) <= self::TERMINAL_COMMAND_OUTPUT_LIMIT) {
            return $output;
        }

        return substr($output, 0, self::TERMINAL_COMMAND_OUTPUT_LIMIT - strlen($truncationMarker)).$truncationMarker;
    }
}
