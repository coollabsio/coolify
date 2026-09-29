<?php

namespace App\Models;

use App\Contracts\ThrottledNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Records when a notification was last sent for a resource, so repeated checks
 * (across queue workers) do not send the same notification again.
 */
class NotificationThrottle extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'notification',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Atomically reserve the right to send a notification.
     *
     * Returns true when the notification was never sent, or when it was last sent
     * before $sentBefore. Without $sentBefore, it is sent only once until released.
     */
    public static function claim(Model $notifiable, string $notification, ?CarbonInterface $sentBefore = null): bool
    {
        if ($sentBefore !== null) {
            $renewed = static::query()
                ->whereMorphedTo('notifiable', $notifiable)
                ->where('notification', $notification)
                ->where('sent_at', '<', $sentBefore)
                ->update(['sent_at' => now()]);

            if ($renewed === 1) {
                return true;
            }
        }

        return static::query()->insertOrIgnore([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'notification' => $notification,
            'sent_at' => now(),
        ]) === 1;
    }

    /**
     * Claim the throttle a notification declares. Notifications without a throttle subject are always allowed.
     */
    public static function claimFor(ThrottledNotification $notification): bool
    {
        $subject = $notification->throttleSubject();
        if ($subject === null) {
            return true;
        }

        return static::claim($subject, $notification::class, now()->subMinutes($notification->throttleIntervalMinutes()));
    }

    /**
     * Mark a notification as sent without sending it.
     */
    public static function record(Model $notifiable, string $notification): void
    {
        static::query()->updateOrInsert(
            [
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'notification' => $notification,
            ],
            ['sent_at' => now()],
        );
    }

    /**
     * Forget a sent notification. Returns true when a record was removed.
     */
    public static function release(Model $notifiable, string $notification): bool
    {
        return static::query()
            ->whereMorphedTo('notifiable', $notifiable)
            ->where('notification', $notification)
            ->delete() > 0;
    }

    public static function wasSent(Model $notifiable, string $notification): bool
    {
        return static::query()
            ->whereMorphedTo('notifiable', $notifiable)
            ->where('notification', $notification)
            ->exists();
    }
}
