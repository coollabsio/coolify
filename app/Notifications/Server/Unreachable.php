<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Notifications\Messages\MailMessage;

class Unreachable extends CustomEmailNotification
{
    protected bool $isRateLimited = false;

    public function __construct(public Server $server)
    {
        $this->onQueue('high');
        $this->isRateLimited = isEmailRateLimited(
            limiterKey: 'server-unreachable:'.$this->server->id,
        );
    }

    public function via(object $notifiable): array
    {
        if ($this->isRateLimited) {
            return [];
        }

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


        $executed = RateLimiter::attempt(
            'notification-server-unreachable-'.$this->server->uuid,
            1,
            function () use ($channels) {
        return $channels;
            },
            7200,
        );


        if (! $executed) {
            return [];
        }

        return $executed;
    }

    public function toMail(): ?MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Your server ({$this->server->name}) is unreachable.");
        $mail->view('emails.server-lost-connection', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toDiscord(): ?DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Server unreachable',
            description: "Your server '{$this->server->name}' is unreachable.",
            color: DiscordMessage::errorColor(),
        );

        $message->addField('IMPORTANT', 'We automatically try to revive your server and turn on all automations & integrations.');

        return $message;
    }

    public function toNtfy(): array
    {
        return [
            'title' => "Coolify: Your server '{$this->server->name}' is unreachable.",
            'message' => 'All automations & integrations are turned off! Please check your server! IMPORTANT: We automatically try to revive your server and turn on all automations & integrations.',
        ];
    }

    public function toTelegram(): ?array
    {
        return [
            'message' => "Coolify: Your server '{$this->server->name}' is unreachable. All automations & integrations are turned off! Please check your server! IMPORTANT: We automatically try to revive your server and turn on all automations & integrations.",
        ];
    }
}
