<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToPushoverJob;
use Illuminate\Notifications\Notification;

class PushoverChannel
{
  public function send(SendsPushover $notifiable, Notification $notification): void
  {
    $message = $notification->toPushover();
    $pushoverSettings = $notifiable->pushoverNotificationSettings;

    if (! $pushoverSettings || ! $pushoverSettings->isEnabled() || ! $pushoverSettings->pushover_user || ! $pushoverSettings->pushover_token) {
      return;
    }

    SendMessageToPushoverJob::dispatch($message, $pushoverSettings->pushover_token, $pushoverSettings->pushover_user);
  }
}
