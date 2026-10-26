<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A notification that is sent at most once per interval for the same resource.
 * Team::notify() checks the throttle before it sends the notification.
 */
interface ThrottledNotification
{
    /**
     * The resource the throttle belongs to. Return null to send without a throttle.
     */
    public function throttleSubject(): ?Model;

    public function throttleIntervalMinutes(): int;
}
