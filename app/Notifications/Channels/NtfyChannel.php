<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToNtfyJob;
use Illuminate\Notifications\Notification;

class NtfyChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsNtfy $notifiable, Notification $notification): void
    {
        $message = $notification->toNtfy();
        $ntfySettings = $notifiable->ntfyNotificationSettings;

        if (! $ntfySettings || ! $ntfySettings->isEnabled() || ! $ntfySettings->ntfy_url || ! $ntfySettings->ntfy_topic) {
            return;
        }

        $message->customPriority = $ntfySettings->getPriorityForLevel($message->level);

        SendMessageToNtfyJob::dispatch(
            $message,
            $ntfySettings->ntfy_url,
            $ntfySettings->ntfy_topic,
            $ntfySettings->ntfy_token,
            $ntfySettings->ntfy_username,
            $ntfySettings->ntfy_password,
        );
    }
}
