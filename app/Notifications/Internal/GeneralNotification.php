<?php

namespace App\Notifications\Internal;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public string $message)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        $channels = [];
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');
        $isNtfyEnabled = data_get($notifiable, 'ntfy_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        if ($isNtfyEnabled) {
            $channels[] = NtfyChannel::class;
        }

        return $channels;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: 'Coolify: General Notification',
            description: $this->message,
            color: DiscordMessage::infoColor(),
        );
    }

    public function toNtfy(): array
    {
        return [
            'message' => $this->message,
            'buttons' => 'view, Go to your dashboard, '.base_url().';',
        ];
    }

    public function toTelegram(): array
    {
        return [
            'message' => $this->message,
        ];
    }
}
