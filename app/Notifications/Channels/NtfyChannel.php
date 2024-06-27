<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToDiscordJob;
use App\Jobs\SendMessageToNtfyJob;
use Illuminate\Notifications\Notification;

class NtfyChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsNtfy $notifiable, Notification $notification): void
    {
        $content = $notification->toNtfy($notifiable);
        $message = $content['message'] ?? null;
        $buttons = $content['buttons'] ?? null;
        $emoji = $content['emoji'] ?? null;
        $title = $content['title'] ?? null;

        $url_info = $notifiable->routeNotificationForNtfy();
        $topic = $url_info['topic'];
        $url = $url_info['url'];
        $username = $url_info['username'];
        $password = $url_info['password'];
        if (! $url_info) {
            return;
        }

        dispatch(new SendMessageToNtfyJob(
            $message,
            $buttons,
            $emoji,
            $title,
            $url,
            $username,
            $password,
            $topic
        ));
    }
}
