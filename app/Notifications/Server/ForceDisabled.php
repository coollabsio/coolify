<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\ExternalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ForceDisabled extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public Server $server) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        $isEmailEnabled = isEmailEnabled($notifiable);
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');
        $isExternalEnabled = data_get($notifiable, 'external_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isExternalEnabled) {
            $channels[] = ExternalChannel::class;
        }
        if ($isEmailEnabled) {
            $channels[] = EmailChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) disabled because it is not paid!");
        $mail->view('emails.server-force-disabled', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toExternal(): mixed {
        return [
            'event' => 'subscription_expired',
            'server' => $this->server->name
        ];
    }

    public function toDiscord(): string
    {
        $message = "Coolify: Server ({$this->server->name}) disabled because it is not paid!\n All automations and integrations are stopped.\nPlease update your subscription to enable the server again [here](https://app.coolify.io/subsciprtions).";

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server ({$this->server->name}) disabled because it is not paid!\n All automations and integrations are stopped.\nPlease update your subscription to enable the server again [here](https://app.coolify.io/subsciprtions).",
        ];
    }
}
