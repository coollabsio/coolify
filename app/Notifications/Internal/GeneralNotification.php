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
    {}

    public function via(object $notifiable): array
    {
        $channels[] = DiscordChannel::class;
        return $channels;
    }

    public function toDiscord(): string
    {
        return $this->message;
    }
}
