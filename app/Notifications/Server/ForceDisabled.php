<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ForceDisabled extends CustomEmailNotification
{
    public function __construct(public Server $server)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
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

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) disabled because it is not paid!");
        $mail->view('emails.server-force-disabled', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Server disabled',
            description: "Server ({$this->server->name}) disabled because it is not paid!",
            color: DiscordMessage::errorColor(),
        );

        $message->addField('Please update your subscription to enable the server again!', '[Link](https://app.coolify.io/subscriptions)');

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server ({$this->server->name}) disabled because it is not paid!\n All automations and integrations are stopped.\nPlease update your subscription to enable the server again [here](https://app.coolify.io/subscriptions).",
        ];
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Server disabled';
        $description = "Server ({$this->server->name}) disabled because it is not paid!\n";
        $description .= "All automations and integrations are stopped.\n\n";
        $description .= 'Please update your subscription to enable the server again: https://app.coolify.io/subscriptions';

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
