<?php

namespace App\Notifications\Server;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Revived extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public function __construct(public Server $server)
    {
        if ($this->server->unreachable_email_sent === false) {
            return;
        }
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'status_changes');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("✅ Server ({$this->server->name}) revived.");
        $mail->view('emails.server-revived', [
            'name' => $this->server->name,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        $message = "✅ Server '{$this->server->name}' revived. All automations & integrations are turned on again!";
        return $message;
    }
    public function toTelegram(): array
    {
        return [
            "message" => "✅ Server '{$this->server->name}' revived. All automations & integrations are turned on again!"
        ];
    }
}
