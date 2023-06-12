<?php

namespace App\Notifications\Channels;

use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class EmailChannel
{
    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        $this->bootConfigs($notifiable);

        $is_test_notification = $notification instanceof \App\Notifications\TestNotification;

        if ($is_test_notification) {
            $bcc = $notifiable->routeNotificationForEmail('smtp_test_recipients');
        } else {
            $bcc = $notifiable->routeNotificationForEmail();
        }
        $mailMessage = $notification->toMail($notifiable);

        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from(
                    $notifiable->extra_attributes?->get('smtp_from_address'),
                    $notifiable->extra_attributes?->get('smtp_from_name')
                )
                ->bcc($bcc)
                ->subject($mailMessage->subject)
                ->html((string)$mailMessage->render())
        );
    }

    private function bootConfigs($notifiable): void
    {
        config()->set('mail.default', 'smtp');
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
