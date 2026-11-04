<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Support\Facades\Log;

/**
 * Tells the root team when an automatic update starts, fails, or finishes.
 * The new version reports the result after it starts, so the attempt is saved in the instance settings.
 */
class AutoUpdateNotifier
{
    /** An attempt that has not reached the target version after this many minutes is reported as failed. */
    public const RESULT_TIMEOUT_MINUTES = 30;

    public static function started(string $currentVersion, string $targetVersion): void
    {
        instanceSettings()->update([
            'auto_update_target_version' => $targetVersion,
            'auto_update_started_at' => now(),
        ]);

        self::notify("Automatic update started: Coolify {$currentVersion} to {$targetVersion}.", true);
    }

    public static function failed(string $reason): void
    {
        self::clearAttempt();

        self::notify("Automatic update failed: {$reason}\n\nFix the cause, then start the update again: ".base_url().'/settings/updates', false);
    }

    /**
     * Reports the result of the saved attempt: success when this version is the target,
     * failure when the upgrade script wrote an error or the attempt timed out.
     */
    public static function checkResult(): void
    {
        $settings = instanceSettings();
        $targetVersion = $settings->auto_update_target_version;
        if (blank($targetVersion)) {
            return;
        }

        $runningVersion = (string) config('constants.coolify.version');
        if (CoolifyUpgradeStatus::hasReachedTargetVersion($runningVersion, $targetVersion)) {
            self::clearAttempt();
            self::notify("Automatic update finished: Coolify is now on {$runningVersion}.", true);

            return;
        }

        $status = self::readUpgradeStatus();
        [$step, $message] = array_pad(explode('|', trim($status)), 2, '');
        if ($step === 'error') {
            self::failed("the upgrade to {$targetVersion} stopped with this error: {$message}. Log: /data/coolify/source/upgrade-*.log");

            return;
        }

        $startedAt = $settings->auto_update_started_at;
        $timedOut = ! $startedAt || $startedAt->lt(now()->subMinutes(self::RESULT_TIMEOUT_MINUTES));
        if ($timedOut && ! CoolifyUpgradeStatus::isRunning($status)) {
            self::failed("Coolify {$targetVersion} did not start within ".self::RESULT_TIMEOUT_MINUTES." minutes. Coolify {$runningVersion} is still running. Log: /data/coolify/source/upgrade-*.log");
        }
    }

    private static function readUpgradeStatus(): string
    {
        try {
            $server = Server::find(0);

            return $server ? (string) instant_remote_process(['cat '.CoolifyUpgradeStatus::FILE.' 2>/dev/null || true'], $server, false) : '';
        } catch (\Throwable $e) {
            Log::warning('Could not read the upgrade status', ['error' => $e->getMessage()]);

            return '';
        }
    }

    private static function clearAttempt(): void
    {
        instanceSettings()->update([
            'auto_update_target_version' => null,
            'auto_update_started_at' => null,
        ]);
    }

    private static function notify(string $message, bool $success): void
    {
        try {
            Team::find(0)?->notify(new GeneralNotification($message, $success));
        } catch (\Throwable $e) {
            Log::warning('Could not send the automatic update notification', ['error' => $e->getMessage()]);
        }
    }
}
