<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToDiscordJob;
use App\Models\InstanceSettings;
use Illuminate\Notifications\Notification;

class DiscordChannel
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toDiscord($notifiable);

        $webhookUrl = data_get(
            InstanceSettings::get(),
            'extra_attributes.discord_webhook'
        );

        dispatch(new SendMessageToDiscordJob($message, $webhookUrl));
    }
}
