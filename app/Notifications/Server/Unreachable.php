<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Unreachable extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public function __construct(public Server $server)
    {

    }

    public function via(object $notifiable): array
    {
        $channels = [];
        $isEmailEnabled = isEmailEnabled($notifiable);
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isEmailEnabled ) {
            $channels[] = EmailChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("Coolify: Server ({$this->server->name}) is unreachable after trying to connect to it 3 times");
        $mail->view('emails.server-lost-connection', [
            'name' => $this->server->name,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        $message = "Coolify: Server '{$this->server->name}' is unreachable after trying to connect to it 3 times. All automations & integrations are turned off! Please check your server! IMPORTANT: We automatically try to revive your server. If your server is back online, we will automatically turn on all automations & integrations.";
        return $message;
    }
    public function toTelegram(): array
    {
        return [
            "message" => "Coolify: Server '{$this->server->name}' is unreachable after trying to connect to it 3 times. All automations & integrations are turned off! Please check your server! IMPORTANT: We automatically try to revive your server. If your server is back online, we will automatically turn on all automations & integrations."
        ];
    }
}
