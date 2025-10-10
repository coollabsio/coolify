<?php

namespace App\Notifications\Channels;

use App\Jobs\SendWebhookJob;
use Illuminate\Notifications\Notification;

class WebhookChannel
{
    /**
     * Send the given notification.
     */
    public function send($notifiable, Notification $notification): void
    {
        $webhookSettings = $notifiable->webhookNotificationSettings;

        if (! $webhookSettings || ! $webhookSettings->isEnabled() || ! $webhookSettings->webhook_url) {
            if (isDev()) {
                ray('Webhook notification skipped - not enabled or no URL configured');
            }

            return;
        }

        $payload = $notification->toWebhook();

        if (isDev()) {
            ray('Dispatching webhook notification', [
                'notification' => get_class($notification),
                'url' => $webhookSettings->webhook_url,
                'payload' => $payload,
            ]);
        }

        SendWebhookJob::dispatch($payload, $webhookSettings->webhook_url);
    }
}
