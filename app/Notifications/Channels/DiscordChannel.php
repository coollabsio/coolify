<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToDiscordJob;
use Illuminate\Notifications\Notification;

class DiscordChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsDiscord $notifiable, Notification $notification): void
    {
        $message = $notification->toDiscord();
        $webhookUrl = $notifiable->routeNotificationForDiscord();
        if (! $webhookUrl) {
            return;
        }
        SendMessageToDiscordJob::dispatch($message, $webhookUrl);
    }
}
