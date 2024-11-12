<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Unreachable extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    protected bool $isRateLimited = false;

    public function __construct(public Server $server)
    {
        $this->isRateLimited = isEmailRateLimited(
            limiterKey: 'server-unreachable:' . $this->server->id,
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
        $isSlackEnabled = data_get($notifiable, 'slack_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isEmailEnabled) {
            $channels[] = EmailChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        if ($isSlackEnabled) {
            $channels[] = SlackChannel::class;
        }

        return $channels;
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

    public function toTelegram(): ?array
    {
        return [
            'message' => "Coolify: Your server '{$this->server->name}' is unreachable. All automations & integrations are turned off! Please check your server! IMPORTANT: We automatically try to revive your server and turn on all automations & integrations.",
        ];
    }


    public function toSlack(): SlackMessage
    {
        $description = "Your server '{$this->server->name}' is unreachable.\n";
        $description .= "All automations & integrations are turned off!\n\n";
        $description .= "*IMPORTANT:* We automatically try to revive your server and turn on all automations & integrations.";

        return new SlackMessage(
            title: 'Server unreachable',
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
