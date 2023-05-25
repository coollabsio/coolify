<?php

namespace App\Notifications\Channels;

use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class CoolifyEmailChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsCoolifyEmail $notifiable, Notification $notification): void
    {
        $this->bootConfigs($notifiable);
        $bcc = $notifiable->routeNotificationForCoolifyEmail();
        $mailMessage = $notification->toMail($notifiable);

        Mail::send([], [], fn(Message $message) => $message
            ->from(
                $notifiable->extra_attributes?->get('from_address'),
                $notifiable->extra_attributes?->get('from_name')
            )
            ->bcc($bcc)
            ->subject($mailMessage->subject)
            ->html((string)$mailMessage->render())
        );
    }

    private function bootConfigs($notifiable): void
    {
        config()->set('mail.mailers.smtp', [
            "transport" => "smtp",
            "host" => $notifiable->extra_attributes?->get('smtp_host'),
            "port" => $notifiable->extra_attributes?->get('smtp_port'),
            "encryption" => $notifiable->extra_attributes?->get('smtp_encryption'),
            "username" => $notifiable->extra_attributes?->get('smtp_username'),
            "password" => $notifiable->extra_attributes?->get('smtp_password'),
            "timeout" => $notifiable->extra_attributes?->get('smtp_timeout'),
            "local_domain" => null,
        ]);
    }
}
