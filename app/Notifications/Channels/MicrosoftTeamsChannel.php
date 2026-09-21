<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToMicrosoftTeamsJob;
use Illuminate\Notifications\Notification;

class MicrosoftTeamsChannel
{
    /**
     * Send the given notification.
     *
     * Reuses the notification's toSlack() payload: App\Notifications\Dto\SlackMessage
     * ({title, description, color}) maps one-to-one onto a Teams MessageCard.
     */
    public function send(SendsMicrosoftTeams $notifiable, Notification $notification): void
    {
        $message = $notification->toSlack();
        $settings = $notifiable->microsoftTeamsNotificationSettings;

        if (! $settings || ! $settings->isEnabled() || ! $settings->microsoft_teams_webhook_url) {
            return;
        }

        SendMessageToMicrosoftTeamsJob::dispatch($message, $settings->microsoft_teams_webhook_url);
    }
}
