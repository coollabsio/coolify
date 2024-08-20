<?php

namespace App\Notifications\Internal;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\ExternalChannel;
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
        $isExternalEnabled = data_get($notifiable, 'external_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        if ($isExternalEnabled) {
            $channels[] = ExternalChannel::class;
        }

        return $channels;
    }

    public function toExternal(): mixed {
        return [
            'event' => 'message',
            'message' => $this->message
        ];
    }

    public function toDiscord(): string
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
