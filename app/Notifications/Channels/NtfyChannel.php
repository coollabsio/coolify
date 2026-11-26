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

        // Values of a previously selected auth method can still be stored, so send only the selected one.
        $authMethod = $ntfySettings->ntfy_auth_method;

        SendMessageToNtfyJob::dispatch(
            $message,
            $ntfySettings->ntfy_url,
            $ntfySettings->ntfy_topic,
            $authMethod === 'token' ? $ntfySettings->ntfy_token : null,
            $authMethod === 'basic' ? $ntfySettings->ntfy_username : null,
            $authMethod === 'basic' ? $ntfySettings->ntfy_password : null,
        );
    }
}
