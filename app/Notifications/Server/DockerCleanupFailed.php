<?php

namespace App\Notifications\Server;

use App\Contracts\ThrottledNotification;
use App\Models\NotificationThrottle;
use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent on the first failure, then at most once a day until a cleanup on the server succeeds again.
 */
class DockerCleanupFailed extends CustomEmailNotification implements ThrottledNotification
{
    public function __construct(public Server $server, public string $message)
    {
        $this->onQueue('high');
    }

    public function throttleSubject(): ?Model
    {
        return $this->server;
    }

    public function throttleIntervalMinutes(): int
    {
        return NotificationThrottle::FAILURE_REMINDER_INTERVAL_MINUTES;
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
            'reminder' => NotificationThrottle::FAILURE_REMINDER_TEXT,
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

    public function toWebhook(): array
    {
        $url = base_url().'/server/'.$this->server->uuid;

        return [
            'success' => false,
            'message' => 'Docker cleanup job failed',
            'event' => 'docker_cleanup_failed',
            'server_name' => $this->server->name,
            'server_uuid' => $this->server->uuid,
            'error_message' => $this->message,
            'url' => $url,
        ];
    }
}
