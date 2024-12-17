<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ForceEnabled extends CustomEmailNotification
{
    public function __construct(public Server $server)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_force_enabled');
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

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server ({$this->server->name}) enabled again!",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Server enabled',
            level: 'success',
            message: "Server ({$this->server->name}) enabled again!",
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Server enabled',
            description: "Server '{$this->server->name}' enabled again!",
            color: SlackMessage::successColor()
        );
    }
}
