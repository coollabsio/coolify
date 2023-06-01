<?php

namespace App\Notifications\Channels;

use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class EmailChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        $this->bootConfigs($notifiable);
        if ($notification instanceof \App\Notifications\TestNotification) {
            $bcc = $notifiable->routeNotificationForEmail('test_address');
            if (count($bcc) === 1) {
                $bcc = $notifiable->routeNotificationForEmail();
            }
        } else {
            $bcc = $notifiable->routeNotificationForEmail();
        }
        $mailMessage = $notification->toMail($notifiable);

        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from(
                    $notifiable->smtp_attributes?->get('from_address'),
                    $notifiable->smtp_attributes?->get('from_name')
                )
                ->cc($bcc)
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
            "host" => $notifiable->smtp_attributes?->get('smtp_host'),
            "port" => $notifiable->smtp_attributes?->get('smtp_port'),
            "encryption" => $notifiable->smtp_attributes?->get('smtp_encryption'),
            "username" => $notifiable->smtp_attributes?->get('smtp_username'),
            "password" => $notifiable->smtp_attributes?->get('smtp_password'),
            "timeout" => $notifiable->smtp_attributes?->get('smtp_timeout'),
            "local_domain" => null,
        ]);
    }
}
