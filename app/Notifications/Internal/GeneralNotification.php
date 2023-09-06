<?php

namespace App\Notifications\Internal;

use App\Notifications\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $message)
    {
    }

    public function via(object $notifiable): array
    {
        return [DiscordChannel::class];
    }

    public function toDiscord(): string
    {
        return $this->message;
    }
}
