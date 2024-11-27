<?php

namespace App\Notifications\Container;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ContainerStopped extends CustomEmailNotification
{
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
        $message = new DiscordMessage(
            title: ':cross_mark: Resource stopped',
            description: "{$this->name} has been stopped unexpectedly on {$this->server->name}.",
            color: DiscordMessage::errorColor(),
        );

        if ($this->url) {
            $message->addField('Resource', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toNtfy(): array
    {
        return [
            'title' => "Coolify: A resource ($this->name) has been stopped",
            'message' => "Coolify: A resource ($this->name) has been stopped unexpectedly on {$this->server->name}",
            'buttons' => 'view, Check Proxy in Coolify, '.$this->url.';',
            'emoji' => 'stop_sign',
        ];
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
