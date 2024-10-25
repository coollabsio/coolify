<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Reachable extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    protected bool $isRateLimited = false;

    public function __construct(public Server $server)
    {
        $this->isRateLimited = isEmailRateLimited(
            limiterKey: 'server-reachable:'.$this->server->id,
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

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
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
        $mail->subject("Coolify: Server ({$this->server->name}) revived.");
        $mail->view('emails.server-revived', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ":white_check_mark: Server '{$this->server->name}' revived",
            description: 'All automations & integrations are turned on again!',
            color: DiscordMessage::successColor(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server '{$this->server->name}' revived. All automations & integrations are turned on again!",
        ];
    }
}
