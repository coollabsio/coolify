<?php

namespace App\Notifications;

use App\Notifications\Channels\CoolifyEmailChannel;
use App\Notifications\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DemoNotification extends Notification implements ShouldQueue
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
        $notifiable->extra_attributes?->get('smtp_active') && $channels[] = CoolifyEmailChannel::class;
        $notifiable->extra_attributes?->get('discord_active') && $channels[] = DiscordChannel::class;
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('Coolify demo notification')
                    ->line('Welcome to Coolify!')
                    ->error()
                    ->action('Go to dashboard', url('/'))
                    ->line('We need your attention for disk usage.');
    }

    public function toDiscord(object $notifiable): string
    {
        return 'Welcome to Coolify! We need your attention for disk usage. [Go to dashboard]('.url('/').')';
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
