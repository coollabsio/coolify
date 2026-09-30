<?php

namespace App\Models;

use App\Contracts\ThrottledNotification;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

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
     * Claim the throttle, then run $send. When $send throws, the claim is released so the
     * next attempt is not throttled, and the exception is rethrown.
     *
     * Returns false when the claim failed and $send did not run.
     */
    public static function sendOnce(Model $notifiable, string $notification, ?CarbonInterface $sentBefore, Closure $send): bool
    {
        if (! static::claim($notifiable, $notification, $sentBefore)) {
            return false;
        }

        try {
            $send();
        } catch (Throwable $exception) {
            static::release($notifiable, $notification);

            throw $exception;
        }

        return true;
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

    /**
     * Delete rows whose subject no longer exists. Subjects are often removed with bulk or cascading
     * deletes that skip model events, so this sweep is the reliable cleanup. Soft-deleted subjects are kept.
     *
     * Returns the number of deleted rows.
     */
    public static function deleteOrphans(): int
    {
        $deleted = 0;

        foreach (static::query()->distinct()->pluck('notifiable_type') as $type) {
            $class = Relation::getMorphedModel($type) ?? $type;
            $throttles = static::query()->where('notifiable_type', $type);

            if (! is_a($class, Model::class, true)) {
                $deleted += $throttles->delete();

                continue;
            }

            $subject = new $class;
            $deleted += $throttles->whereNotExists(
                $class::query()
                    ->withoutGlobalScopes()
                    ->selectRaw('1')
                    ->whereColumn($subject->getQualifiedKeyName(), (new static)->qualifyColumn('notifiable_id'))
            )->delete();
        }

        return $deleted;
    }
}
