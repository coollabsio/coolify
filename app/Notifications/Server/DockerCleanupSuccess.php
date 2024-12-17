<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class DockerCleanupSuccess extends CustomEmailNotification
{
    public function __construct(public Server $server, public string $message)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('docker_cleanup_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Docker cleanup job succeeded on {$this->server->name}");
        $mail->view('emails.docker-cleanup-success', [
            'name' => $this->server->name,
            'text' => $this->message,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':white_check_mark: Coolify: Docker cleanup job succeeded on '.$this->server->name,
            description: $this->message,
            color: DiscordMessage::successColor(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Docker cleanup job succeeded on {$this->server->name}!\n\n{$this->message}",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Docker cleanup job succeeded',
            level: 'success',
            message: "Docker cleanup job succeeded on {$this->server->name}!\n\n{$this->message}",
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Coolify: Docker cleanup job succeeded',
            description: "Docker cleanup job succeeded on '{$this->server->name}'!\n\n{$this->message}",
            color: SlackMessage::successColor()
        );
    }
}
