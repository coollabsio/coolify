<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

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

        // TODO: Implement actual webhook delivery
        // This is a placeholder implementation
        // You'll need to:
        // 1. Get the webhook payload from $notification->toWebhook()
        // 2. Create a job to send the HTTP POST request to $webhookSettings->webhook_url
        // 3. Handle retries and errors appropriately

        Log::info('Webhook notification would be sent', [
            'url' => $webhookSettings->webhook_url,
            'notification' => get_class($notification),
        ]);
    }
}
