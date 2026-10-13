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

    public function __construct(public string $message, public bool $success = true)
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
            title: $this->success ? 'Coolify: General Notification' : 'Coolify: Action required',
            description: $this->message,
            color: $this->success ? DiscordMessage::infoColor() : DiscordMessage::errorColor(),
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
            title: $this->success ? 'General Notification' : 'Action required',
            level: $this->success ? 'info' : 'error',
            message: $this->message,
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: $this->success ? 'Coolify: General Notification' : 'Coolify: Action required',
            description: $this->message,
            color: $this->success ? SlackMessage::infoColor() : SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'event' => 'general',
            'url' => base_url(),
        ];
    }
}
