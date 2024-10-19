<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToExternalJob;
use Illuminate\Notifications\Notification;

class ExternalChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsExternal $notifiable, Notification $notification): void
    {
        $message = $notification->toExternal();
        $url = $notifiable->externalURL();
        if (! $url) {
            return;
        }
        dispatch(new SendMessageToExternalJob($message, $url));
    }
}
