<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToTeamsJob;
use Illuminate\Notifications\Notification;

class TeamsChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsTeams $notifiable, Notification $notification): void
    {
        $message = $notification->toTeams();

        $teamsSettings = $notifiable->teamsNotificationSettings;

        if (! $teamsSettings || ! $teamsSettings->isEnabled() || ! $teamsSettings->teams_webhook_url) {
            return;
        }

        SendMessageToTeamsJob::dispatch($message, $teamsSettings->teams_webhook_url);
    }
}
