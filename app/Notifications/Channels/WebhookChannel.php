<?php

namespace App\Notifications\Channels;

use App\Jobs\SendWebhookJob;
use Illuminate\Notifications\Notification;

class WebhookChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsWebhook $notifiable, Notification $notification): void
    {
        $webhookSettings = $notifiable->webhookNotificationSettings;

        if (! $webhookSettings || ! $webhookSettings->isEnabled() || ! $webhookSettings->webhook_url) {
            return;
        }

        $payload = $notification->toWebhook();

        SendWebhookJob::dispatch($payload, $webhookSettings->webhook_url);
    }
}
