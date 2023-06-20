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
        if (($this->type === 'smtp' || is_null($this->type)) && $notifiable->extra_attributes?->get('smtp_enabled') && $notifiable->extra_attributes?->get('notifications_smtp_test')) {
            $channels[] = EmailChannel::class;
        }
        if (($this->type === 'discord' || is_null($this->type)) && $notifiable->extra_attributes?->get('discord_enabled') && $notifiable->extra_attributes?->get('notifications_discord_test')) {
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
