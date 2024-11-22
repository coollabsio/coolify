<?php

namespace App\Notifications\Container;

use App\Models\Server;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContainerRestarted extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public string $name, public Server $server, public ?string $url = null)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'status_changes');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: A resource ({$this->name}) has been restarted automatically on {$this->server->name}");
        $mail->view('emails.container-restarted', [
            'containerName' => $this->name,
            'serverName' => $this->server->name,
            'url' => $this->url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':warning: Resource restarted',
            description: "{$this->name} has been restarted automatically on {$this->server->name}.",
            color: DiscordMessage::infoColor(),
        );

        if ($this->url) {
            $message->addField('Resource', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: A resource ({$this->name}) has been restarted automatically on {$this->server->name}";
        $payload = [
            'message' => $message,
        ];
        if ($this->url) {
            $payload['buttons'] = [
                [
                    [
                        'text' => 'Check Proxy in Coolify',
                        'url' => $this->url,
                    ],
                ],
            ];
        }

        return $payload;
    }
}
