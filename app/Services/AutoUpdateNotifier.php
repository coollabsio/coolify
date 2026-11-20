<?php

namespace App\Services;

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Tells the root team when an automatic update starts, fails, or finishes.
 * The new version reports the result after it starts, so the attempt is saved in the instance settings.
 */
class AutoUpdateNotifier
{
    /** An attempt that has not reached the target version after this many minutes is reported as failed. */
    public const RESULT_TIMEOUT_MINUTES = 30;

    /**
     * Saves the attempt before the upgrade script runs, so the new version can report the result
     * also when the container restarts before started() is called.
     */
    public static function saveAttempt(string $targetVersion): void
    {
        instanceSettings()->update([
            'auto_update_target_version' => $targetVersion,
            'auto_update_started_at' => now(),
        ]);
    }

    public static function started(string $currentVersion, string $targetVersion): void
    {
        self::notify("Automatic update started: Coolify {$currentVersion} to {$targetVersion}.", true);
    }

    public static function failed(string $reason): void
    {
        self::clearAttempt();
        self::notifyFailure($reason);
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

        $startedAt = $settings->auto_update_started_at;
        $runningVersion = (string) config('constants.coolify.version');
        if (CoolifyUpgradeStatus::hasReachedTargetVersion($runningVersion, $targetVersion)) {
            if (self::claimAttempt($settings, $targetVersion)
                && ! self::notify("Automatic update finished: Coolify is now on {$runningVersion}.", true)) {
                self::restoreAttempt($targetVersion, $startedAt);
            }

            return;
        }

        $status = self::readUpgradeStatus();
        [$step, $message, $writtenAt] = array_pad(explode('|', trim($status)), 3, '');
        if ($step === 'error' && self::isWrittenDuringAttempt($writtenAt, $startedAt)) {
            if (self::claimAttempt($settings, $targetVersion)
                && ! self::notifyFailure("the upgrade to {$targetVersion} stopped with this error: {$message}. Log: /data/coolify/source/upgrade-*.log")) {
                self::restoreAttempt($targetVersion, $startedAt);
            }

            return;
        }

        $timedOut = ! $startedAt || $startedAt->lt(now()->subMinutes(self::RESULT_TIMEOUT_MINUTES));
        if ($timedOut && ! CoolifyUpgradeStatus::isRunning($status) && self::claimAttempt($settings, $targetVersion)
            && ! self::notifyFailure("Coolify {$targetVersion} did not start within ".self::RESULT_TIMEOUT_MINUTES." minutes. Coolify {$runningVersion} is still running. Log: /data/coolify/source/upgrade-*.log")) {
            self::restoreAttempt($targetVersion, $startedAt);
        }
    }

    /**
     * An error from an earlier attempt stays in the status file until the new upgrade script writes its first step.
     */
    private static function isWrittenDuringAttempt(string $writtenAt, ?CarbonInterface $startedAt): bool
    {
        if (! $startedAt) {
            return true;
        }

        try {
            return Carbon::parse(trim($writtenAt))->gte($startedAt->copy()->startOfSecond());
        } catch (\Throwable) {
            return false;
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

    /**
     * Clears the attempt only if it is still saved for this target version.
     * Returns false when another run already reported it, so each result is sent one time.
     */
    private static function claimAttempt(InstanceSettings $settings, string $targetVersion): bool
    {
        $claimed = InstanceSettings::query()
            ->whereKey($settings->getKey())
            ->where('auto_update_target_version', $targetVersion)
            ->update([
                'auto_update_target_version' => null,
                'auto_update_started_at' => null,
            ]) === 1;

        $settings->refresh();

        return $claimed;
    }

    /**
     * Saves a claimed attempt again after its result could not be queued, so the next check sends it again.
     * Does nothing when a newer attempt was saved in the meantime.
     */
    private static function restoreAttempt(string $targetVersion, ?CarbonInterface $startedAt): void
    {
        InstanceSettings::query()
            ->whereKey(0)
            ->whereNull('auto_update_target_version')
            ->update([
                'auto_update_target_version' => $targetVersion,
                'auto_update_started_at' => $startedAt,
            ]);
    }

    private static function clearAttempt(): void
    {
        instanceSettings()->update([
            'auto_update_target_version' => null,
            'auto_update_started_at' => null,
        ]);
    }

    private static function notifyFailure(string $reason): bool
    {
        return self::notify("Automatic update failed: {$reason}\n\nFix the cause, then start the update again: ".base_url().'/settings/updates', false);
    }

    /** Returns false when the notification could not be queued. */
    private static function notify(string $message, bool $success): bool
    {
        try {
            Team::find(0)?->notify(new GeneralNotification($message, $success));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not send the automatic update notification', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
