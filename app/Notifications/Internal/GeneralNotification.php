<?php

namespace App\Notifications\Internal;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\TelegramChannel;
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
        return [TelegramChannel::class, DiscordChannel::class];
    }

    public function toDiscord(): string
    {
        return $this->message;
    }
    public function toTelegram(): array
    {
        return [
            "message" => $this->message,
        ];
    }
}
