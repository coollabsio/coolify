<?php

namespace App\Notifications\Server;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Unreachable extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public function __construct(public Server $server)
    {

    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'status_changes');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("⛔ Server ({$this->server->name}) is unreachable after trying to connect to it 5 times");
        $mail->view('emails.server-lost-connection', [
            'name' => $this->server->name,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        $message = "⛔ Server '{$this->server->name}' is unreachable after trying to connect to it 5 times. All automations & integrations are turned off! Please check your server! IMPORTANT: You have to validate your server again after you fix the issue.";
        return $message;
    }
    public function toTelegram(): array
    {
        return [
            "message" => "⛔ Server '{$this->server->name}' is unreachable after trying to connect to it 5 times. All automations & integrations are turned off! Please check your server! IMPORTANT: You have to validate your server again after you fix the issue."
        ];
    }
}
