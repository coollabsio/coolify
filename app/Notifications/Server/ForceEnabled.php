<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ForceEnabled extends Notification implements ShouldQueue
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
        $isNtfyEnabled = data_get($notifiable, 'ntfy_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isEmailEnabled) {
            $channels[] = EmailChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }

        if ($isNtfyEnabled) {
            $channels[] = NtfyChannel::class;
        }

        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) enabled again!");
        $mail->view('emails.server-force-enabled', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':white_check_mark: Server enabled',
            description: "Server '{$this->server->name}' enabled again!",
            color: DiscordMessage::successColor(),
        );
    }

    public function toNtfy(): array
    {
        return [
            'title' => "Coolify: Server ({$this->server->name}) enabled again!",
            'message' => "Coolify: Server ({$this->server->name}) enabled again!",
        ];
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server ({$this->server->name}) enabled again!",
        ];
    }
}
