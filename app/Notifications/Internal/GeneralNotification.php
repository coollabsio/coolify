<?php

namespace App\Notifications\Internal;

use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
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
        return $notifiable->getEnabledChannels('general');
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: 'Coolify: General Notification',
            description: $this->message,
            color: DiscordMessage::infoColor(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => $this->message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'General Notification',
            level: 'info',
            message: $this->message,
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Coolify: General Notification',
            description: $this->message,
            color: SlackMessage::infoColor(),
        );
    }
}
