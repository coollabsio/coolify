<?php

namespace App\Notifications;

use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];
        $notifiable->extra_attributes?->get('smtp_active') && $channels[] = EmailChannel::class;
        $notifiable->extra_attributes?->get('discord_active') && $channels[] = DiscordChannel::class;
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Coolify Test Notification')
            ->line('Congratulations!')
            ->line('You have successfully received a test Email notification from Coolify. 🥳');
    }

    public function toDiscord(object $notifiable): string
    {
        return 'You have successfully received a test Discord notification from Coolify. 🥳 [Go to your dashboard](' . url('/') . ')';
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
