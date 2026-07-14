<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToSlackJob;
use Illuminate\Notifications\Notification;

class SlackChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsSlack $notifiable, Notification $notification): void
    {
        $message = $notification->toSlack();
        $slackSettings = $notifiable->slackNotificationSettings;

        if (! $slackSettings || ! $slackSettings->isEnabled() || ! $slackSettings->slack_webhook_url) {
            return;
        }

        if ($slackSettings->include_project_name_in_title) {
            $message->prependProjectNameToTitle();
        }

        SendMessageToSlackJob::dispatch($message, $slackSettings->slack_webhook_url);
    }
}
