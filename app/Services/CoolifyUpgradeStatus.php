<?php

namespace App\Services;

use DateTimeInterface;

class CoolifyUpgradeStatus
{
    public const STALE_AFTER_MINUTES = 10;

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

    public static function hasReachedTargetVersion(string $runningVersion, string $targetVersion): bool
    {
        if ($runningVersion === '' || $targetVersion === '') {
            return false;
        }

        return version_compare($runningVersion, $targetVersion, '>=');
    }
}
