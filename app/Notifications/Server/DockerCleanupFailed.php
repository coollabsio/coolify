<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class DockerCleanupFailed extends CustomEmailNotification
{
    public function __construct(public Server $server, public string $message)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('docker_cleanup_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] Docker cleanup job failed on {$this->server->name}");
        $mail->view('emails.docker-cleanup-failed', [
            'name' => $this->server->name,
            'text' => $this->message,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':cross_mark: Coolify: [ACTION REQUIRED] Docker cleanup job failed on '.$this->server->name,
            description: $this->message,
            color: DiscordMessage::errorColor(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: [ACTION REQUIRED] Docker cleanup job failed on {$this->server->name}!\n\n{$this->message}",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Docker cleanup job failed',
            level: 'error',
            message: "[ACTION REQUIRED] Docker cleanup job failed on {$this->server->name}!\n\n{$this->message}",
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Coolify: [ACTION REQUIRED] Docker cleanup job failed',
            description: "Docker cleanup job failed on '{$this->server->name}'!\n\n{$this->message}",
            color: SlackMessage::errorColor()
        );
    }
}
