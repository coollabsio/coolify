<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToPushoverJob;
use Illuminate\Notifications\Notification;

class PushoverChannel
{
    public function send(SendsDiscord $notifiable, Notification $notification): void
    {
        $data = $notification->toPushover($notifiable);
        $pushoverData = $notifiable->routeNotificationForPushover();
        $message = data_get($data, 'message');
        $buttons = data_get($data, 'buttons', []);
        $pushoverToken = data_get($pushoverData, 'token');
        $pushoverUser = data_get($pushoverData, 'user');

        if (! $pushoverToken || ! $pushoverUser || ! $message) {
            return;
        }
        dispatch(new SendMessageToPushoverJob($message, $buttons, $pushoverToken, $pushoverUser));
    }
}
