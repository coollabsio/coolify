<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class SchedulerLogParser
{
    /**
     * Get recent skip events from the scheduled log files.
     *
     * @return Collection<int, array{timestamp: string, type: string, reason: string, team_id: ?int, context: array}>
     */
    public function getRecentSkips(int $limit = 100, ?int $teamId = null): Collection
    {
        $logFiles = $this->getLogFiles();

        $skips = collect();

        foreach ($logFiles as $logFile) {
            $lines = $this->readLastLines($logFile, 2000);

            foreach ($lines as $line) {
                $entry = $this->parseLogLine($line);
                if ($entry === null || ! isset($entry['context']['skip_reason'])) {
                    continue;
                }

                if ($teamId !== null && ($entry['context']['team_id'] ?? null) !== $teamId) {
                    continue;
                }

                $skips->push([
                    'timestamp' => $entry['timestamp'],
                    'type' => $entry['context']['type'] ?? 'unknown',
                    'reason' => $entry['context']['skip_reason'],
                    'team_id' => $entry['context']['team_id'] ?? null,
                    'context' => $entry['context'],
                ]);
            }
        }

        return $skips->sortByDesc('timestamp')->values()->take($limit);
    }

    /**
     * Get recent manager execution logs (start/complete events).
     *
     * @return Collection<int, array{timestamp: string, message: string, duration_ms: ?int, dispatched: ?int, skipped: ?int}>
     */
    public function getRecentRuns(int $limit = 60, ?int $teamId = null): Collection
    {
        $logFiles = $this->getLogFiles();

        $runs = collect();

        foreach ($logFiles as $logFile) {
            $lines = $this->readLastLines($logFile, 2000);

            foreach ($lines as $line) {
                $entry = $this->parseLogLine($line);
                if ($entry === null) {
                    continue;
                }

                if (! str_contains($entry['message'], 'ScheduledJobManager')) {
                    continue;
                }

                $runs->push([
                    'timestamp' => $entry['timestamp'],
                    'message' => $entry['message'],
                    'duration_ms' => $entry['context']['duration_ms'] ?? null,
                    'dispatched' => $entry['context']['dispatched'] ?? null,
                    'skipped' => $entry['context']['skipped'] ?? null,
                ]);
            }
        }

        return $runs->sortByDesc('timestamp')->values()->take($limit);
    }

    private function getLogFiles(): array
    {
        $logDir = storage_path('logs');
        if (! File::isDirectory($logDir)) {
            return [];
        }

        $files = File::glob($logDir.'/scheduled-*.log');

        // Sort by modification time, newest first
        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        // Only check last 3 days of logs
        return array_slice($files, 0, 3);
    }

    /**
     * @return array{timestamp: string, level: string, message: string, context: array}|null
     */
    private function parseLogLine(string $line): ?array
    {
        // Laravel daily log format: [2024-01-15 10:30:00] production.INFO: Message {"key":"value"}
        if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.+)$/', $line, $matches)) {
            return null;
        }

        $timestamp = $matches[1];
        $level = $matches[2];
        $rest = $matches[3];

        // Extract JSON context if present
        $context = [];
        if (preg_match('/^(.+?)\s+(\{.+\})\s*$/', $rest, $contextMatches)) {
            $message = $contextMatches[1];
            $decoded = json_decode($contextMatches[2], true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        } else {
            $message = $rest;
        }

        return [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Efficiently read the last N lines of a file.
     *
     * @return string[]
     */
    private function readLastLines(string $filePath, int $lines): array
    {
        if (! File::exists($filePath)) {
            return [];
        }

        $fileSize = File::size($filePath);
        if ($fileSize === 0) {
            return [];
        }

        // For small files, read the whole thing
        if ($fileSize < 1024 * 1024) {
            $content = File::get($filePath);

            return array_filter(explode("\n", $content), fn ($line) => $line !== '');
        }

        // For large files, read from the end
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $result = [];
        $chunkSize = 8192;
        $buffer = '';
        $position = $fileSize;

        while ($position > 0 && count($result) < $lines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $buffer = fread($handle, $readSize).$buffer;

            $bufferLines = explode("\n", $buffer);
            $buffer = array_shift($bufferLines);

            $result = array_merge(array_filter($bufferLines, fn ($line) => $line !== ''), $result);
        }

        if ($buffer !== '' && count($result) < $lines) {
            array_unshift($result, $buffer);
        }

        fclose($handle);

        return array_slice($result, -$lines);
    }
}
