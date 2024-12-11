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

        $discordSettings = $notifiable->discordNotificationSettings;

        if (! $discordSettings || ! $discordSettings->isEnabled() || ! $discordSettings->discord_webhook_url) {
            return;
        }

        SendMessageToDiscordJob::dispatch($message, $discordSettings->discord_webhook_url);
    }
}
