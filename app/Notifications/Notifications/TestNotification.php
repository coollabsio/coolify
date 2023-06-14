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
    public function via(object $notifiable): array
    {
        $channels = [];
        $notifiable->extra_attributes?->get('smtp_active') && $channels[] = EmailChannel::class;
        $notifiable->extra_attributes?->get('discord_active') && $channels[] = DiscordChannel::class;
        return $channels;
    }
    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject('Coolify Test Notification')
            ->line('Congratulations!')
            ->line('You have successfully received a test Email notification from Coolify. 🥳');
    }

    public function toDiscord(): string
    {
        return 'You have successfully received a test Discord notification from Coolify. 🥳 [Go to your dashboard](' . base_url() . ')';
    }
}
