<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
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
        return $notifiable->getEnabledChannels('server_force_disabled');
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

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Server disabled',
            level: 'error',
            message: "Server ({$this->server->name}) disabled because it is not paid!\n All automations and integrations are stopped.<br/>Please update your subscription to enable the server again [here](https://app.coolify.io/subscriptions).",
        );
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
