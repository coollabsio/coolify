<?php

namespace App\Notifications\Notifications;

use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public string|null $type = null;
    public function __construct(string|null $type = null)
    {
        $this->type = $type;
    }
    public function via(object $notifiable): array
    {
        $channels = [];

        $isSmtp = $this->type === 'smtp' || is_null($this->type);
        $isDiscord = $this->type === 'discord' || is_null($this->type);
        $isEmailEnabled = data_get($notifiable, 'smtp.enabled');
        $isDiscordEnabled = data_get($notifiable, 'discord.enabled');
        $isSubscribedToEmailTests = data_get($notifiable, 'smtp_notifications.test');
        $isSubscribedToDiscordTests = data_get($notifiable, 'discord_notifications.test');

        if ($isEmailEnabled && $isSubscribedToEmailTests && $isSmtp) {
            $channels[] = EmailChannel::class;
        }
        if ($isDiscordEnabled && $isSubscribedToDiscordTests && $isDiscord) {
            $channels[] = DiscordChannel::class;
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
        return 'This is a test Discord notification from Coolify.

[Go to your dashboard](' . base_url() . ')';
    }
}
