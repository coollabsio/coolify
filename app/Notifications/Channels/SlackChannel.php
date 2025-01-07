<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToSlackJob;
use Illuminate\Notifications\Notification;

class SlackChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsSlack $sendsSlack, Notification $notification): void
    {
        $message = $notification->toSlack();
        $slackSettings = $sendsSlack->slackNotificationSettings;

        if (! $slackSettings || ! $slackSettings->isEnabled() || ! $slackSettings->slack_webhook_url) {
            return;
        }

        SendMessageToSlackJob::dispatch($message, $slackSettings->slack_webhook_url);
    }
}
