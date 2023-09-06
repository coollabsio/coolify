<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Test extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string|null $emails = null)
    {
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'test');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("Test Email");
        $mail->view('emails.test');
        return $mail;
    }

    public function toDiscord(): string
    {
        $message = 'This is a test Discord notification from Coolify.';
        $message .= "\n\n";
        $message .= '[Go to your dashboard](' . base_url() . ')';
        return $message;
    }
    public function toTelegram(): array
    {
        return [
            "message" => 'This is a test Telegram notification from Coolify.',
            "buttons" => [
                [
                    "text" => "Go to your dashboard",
                    "url" =>  base_url()
                ]
            ],
        ];
    }
}
