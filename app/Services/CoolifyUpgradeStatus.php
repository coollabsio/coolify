<?php

namespace App\Services;

use App\Models\Server;
use DateTimeInterface;

class CoolifyUpgradeStatus
{
    public const FILE = '/data/coolify/source/.upgrade-status';

    public const STALE_AFTER_MINUTES = 10;

    /**
     * An upgrade that has not written a status for this long is treated as stopped,
     * so a crashed upgrade does not block new ones forever.
     */
    public const RUNNING_LOCK_EXPIRES_AFTER_MINUTES = 15;

    /**
     * @return array{status: string, step?: int, message?: string, running_version: string, target_version: string}
     */
    public static function fromFile(
        string $content,
        string $runningVersion,
        string $targetVersion,
        ?DateTimeInterface $now = null,
        int $staleAfterMinutes = self::STALE_AFTER_MINUTES,
    ): array {
        $base = [
            'running_version' => $runningVersion,
            'target_version' => $targetVersion,
        ];

        $content = trim($content);
        if ($content === '') {
            return ['status' => 'none', ...$base];
        }

        $parts = explode('|', $content);
        if (count($parts) < 3) {
            return ['status' => 'none', ...$base];
        }

        [$step, $message, $timestamp] = $parts;

        try {
            $statusTime = new \DateTime($timestamp);
            $now = $now ?? new \DateTime;
            $diffMinutes = ($now->getTimestamp() - $statusTime->getTimestamp()) / 60;

            if ($diffMinutes > $staleAfterMinutes) {
                return ['status' => 'none', ...$base];
            }
        } catch (\Throwable) {
            return ['status' => 'none', ...$base];
        }

        if ($step === 'error') {
            return [
                'status' => 'error',
                'step' => 0,
                'message' => $message,
                ...$base,
            ];
        }

        $stepInt = (int) $step;

        if ($stepInt >= 6 && ! self::hasReachedTargetVersion($runningVersion, $targetVersion)) {
            return [
                'status' => 'in_progress',
                'step' => $stepInt,
                'message' => "Waiting for Coolify {$targetVersion} to come online...",
                ...$base,
            ];
        }

        $status = $stepInt >= 6 ? 'complete' : 'in_progress';

        return [
            'status' => $status,
            'step' => $stepInt,
            'message' => $message,
            ...$base,
        ];
    }

    /**
     * Writes an error that stopped an upgrade before the upgrade script started, so the upgrade modal shows it.
     * Does nothing while an upgrade script runs, because the error then belongs to this attempt only.
     */
    public static function writeError(Server $server, string $message): void
    {
        $current = (string) instant_remote_process(['cat '.self::FILE.' 2>/dev/null || true'], $server, false);
        if (self::isRunning($current)) {
            return;
        }

        $line = 'error|'.trim(preg_replace('/[|\r\n]+/', ' ', $message)).'|'.now()->toIso8601String();
        instant_remote_process(["echo '".base64_encode($line)."' | base64 -d | tee ".self::FILE.' > /dev/null'], $server, false);
    }

    public static function isRunning(string $content, ?DateTimeInterface $now = null): bool
    {
        $parts = explode('|', trim($content));
        if (count($parts) < 3) {
            return false;
        }

        [$step, , $timestamp] = $parts;
        if (! in_array($step, ['1', '2', '3', '4', '5'], true) || trim($timestamp) === '') {
            return false;
        }

        try {
            $statusTime = new \DateTime($timestamp);
        } catch (\Throwable) {
            return false;
        }

        $now = $now ?? new \DateTime;
        $diffMinutes = ($now->getTimestamp() - $statusTime->getTimestamp()) / 60;

        return $diffMinutes <= self::RUNNING_LOCK_EXPIRES_AFTER_MINUTES;
    }

    public static function hasReachedTargetVersion(string $runningVersion, string $targetVersion): bool
    {
        if ($runningVersion === '' || $targetVersion === '') {
            return false;
        }

        if (! preg_match('/^\d+\.\d+/', $targetVersion)) {
            return true;
        }

        return version_compare($runningVersion, $targetVersion, '>=');
    }
}
