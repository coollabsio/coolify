<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class DockerCleanup extends CustomEmailNotification
{
    public function __construct(public Server $server, public string $message)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('docker_cleanup');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) docker cleanup job done!");
        $mail->view('emails.docker-cleanup', [
            'name' => $this->server->name,
            'message' => $this->message,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':white_check_mark: Server cleanup job done',
            description: $this->message,
            color: DiscordMessage::successColor(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server '{$this->server->name}' cleanup job done!\n\n{$this->message}",
        ];
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Server cleanup job done',
            description: "Server '{$this->server->name}' cleanup job done!\n\n{$this->message}",
            color: SlackMessage::successColor()
        );
    }
}
