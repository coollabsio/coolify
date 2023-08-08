<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
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
        $channels = [];
        $isEmailEnabled = data_get($notifiable, 'smtp_enabled');
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');

        if ($isDiscordEnabled && empty($this->emails)) {
            $channels[] = DiscordChannel::class;
        }

        if ($isEmailEnabled && !empty($this->emails)) {
            $channels[] = EmailChannel::class;
        }
        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("Coolify Test Notification");
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
}
