<?php

namespace App\Notifications\Internal;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public string $message) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');
        $isSlackEnabled = data_get($notifiable, 'slack_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        if ($isSlackEnabled) {
            $channels[] = SlackChannel::class;
        }
        return $channels;
    }

    public function toDiscord(): string
    {
        return $this->message;
    }

    public function toSlack(): string
    {
        return $this->message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => $this->message,
        ];
    }
}
