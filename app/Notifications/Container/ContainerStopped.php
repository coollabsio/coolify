<?php

namespace App\Notifications\Container;

use App\Models\Server;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContainerStopped extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public string $name, public Server $server, public ?string $url = null) {}

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'status_changes');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: A resource  has been stopped unexpectedly on {$this->server->name}");
        $mail->view('emails.container-stopped', [
            'containerName' => $this->name,
            'serverName' => $this->server->name,
            'url' => $this->url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: "Coolify: A resource ($this->name) has been stopped unexpectedly on {$this->server->name}",
            description: 'Please check the output below for more information.',
            color: DiscordMessage::errorColor(),
        );
    }

    public function toTelegram(): array
    {
        $message = "Coolify: A resource ($this->name) has been stopped unexpectedly on {$this->server->name}";
        $payload = [
            'message' => $message,
        ];
        if ($this->url) {
            $payload['buttons'] = [
                [
                    [
                        'text' => 'Open Application in Coolify',
                        'url' => $this->url,
                    ],
                ],
            ];
        }

        return $payload;
    }
}
