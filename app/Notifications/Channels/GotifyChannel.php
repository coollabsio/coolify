<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToGotifyJob;
use Illuminate\Notifications\Notification;

class GotifyChannel
{
    public function send(SendsGotify $notifiable, Notification $notification): void
    {
        $message = $notification->toGotify();
        $gotifySettings = $notifiable->gotifyNotificationSettings;

        if (! $gotifySettings || ! $gotifySettings->isEnabled() || ! $gotifySettings->gotify_url || ! $gotifySettings->gotify_token) {
            return;
        }

        SendMessageToGotifyJob::dispatch($message, $gotifySettings->gotify_url, $gotifySettings->gotify_token);
    }
}
