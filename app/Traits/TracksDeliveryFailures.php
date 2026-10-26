<?php

namespace App\Traits;

use App\Notifications\Internal\GeneralNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Counts permanent delivery failures of a notification channel, the same way
 * servers count failed connection checks in `unreachable_count`.
 *
 * Only configuration errors (an invalid key, token, or URL) count. After
 * DELIVERY_FAILURE_THRESHOLD failures in a row the channel is disabled, and the
 * team admins and the instance admins are told one time. A successful delivery
 * or a change to the settings sets the counter back to zero.
 */
trait TracksDeliveryFailures
{
    public const DELIVERY_FAILURE_THRESHOLD = 3;

    /**
     * The channel name for messages, for example "Discord".
     */
    abstract public function notificationChannelName(): string;

    /**
     * Turn off the channel on the model, without saving.
     */
    abstract protected function disableNotificationChannel(): void;

    public static function bootTracksDeliveryFailures(): void
    {
        static::saving(function (self $settings) {
            if ($settings->exists && array_diff(array_keys($settings->getDirty()), ['failure_count']) !== []) {
                $settings->failure_count = 0;
            }
        });
    }

    /**
     * The reason must not contain a provider response body. It is shared with the
     * team, the team admins, and the instance admins, so it is redacted once more.
     */
    public function recordDeliveryFailure(string $reason): void
    {
        $reason = remove_iip($reason);
        $this->increment('failure_count');

        Log::warning('Notification delivery failed', [
            'channel' => $this->notificationChannelName(),
            'team_id' => $this->team_id,
            'failure_count' => $this->failure_count,
            'reason' => $reason,
        ]);

        if ($this->failure_count < self::DELIVERY_FAILURE_THRESHOLD || ! $this->isEnabled()) {
            return;
        }

        $this->disableNotificationChannel();
        $this->save();

        $channel = $this->notificationChannelName();

        auditLog('notifications.'.Str::lower($channel).'.auto_disabled', [
            'team_id' => $this->team_id,
            'reason' => $reason,
        ], 'warning');

        send_internal_notification(sprintf(
            "%s notifications disabled for team %s after %d failed deliveries.\nReason: %s",
            $channel,
            $this->team_id,
            self::DELIVERY_FAILURE_THRESHOLD,
            $reason
        ));

        $this->sendChannelDisabledEmail($reason);
        $this->notifyOtherChannels($reason);
    }

    public function recordDeliverySuccess(): void
    {
        if ($this->failure_count > 0) {
            $this->failure_count = 0;
            $this->save();
        }
    }

    /**
     * Tell the team through its other enabled channels. The disabled channel is
     * not in the list anymore, and the "general" event never uses email. The
     * root team already gets the internal notification on the same channels.
     */
    private function notifyOtherChannels(string $reason): void
    {
        if ($this->team_id === 0 || ! $this->team) {
            return;
        }

        $channel = $this->notificationChannelName();

        $this->team->notify(new GeneralNotification(
            "{$channel} notifications were disabled after ".self::DELIVERY_FAILURE_THRESHOLD." failed deliveries in a row.\nReason: {$reason}\nPlease fix the settings and enable {$channel} notifications again: ".base_url().'/notifications/'.Str::lower($channel),
            success: false,
        ));
    }

    /**
     * Tell the team admins through the instance email settings, because the
     * team's own channel does not work.
     */
    private function sendChannelDisabledEmail(string $reason): void
    {
        if (! is_transactional_emails_enabled() || ! $this->team) {
            return;
        }

        try {
            $channel = $this->notificationChannelName();
            $mail = new MailMessage;
            $mail->subject("Coolify: {$channel} notifications were disabled");
            $mail->view('emails.notification-channel-disabled', [
                'channel' => $channel,
                'failures' => self::DELIVERY_FAILURE_THRESHOLD,
                'reason' => $reason,
                'url' => base_url().'/notifications/'.Str::lower($channel),
            ]);

            $admins = $this->team->members()->wherePivotIn('role', ['admin', 'owner'])->get(['users.id', 'users.email']);
            foreach ($admins as $admin) {
                send_user_an_email($mail, $admin->email);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to send notification channel disabled email: '.$e->getMessage());
        }
    }
}
