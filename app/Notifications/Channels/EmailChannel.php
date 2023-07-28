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
        ray($notification);
        $recepients = $notifiable->getRecepients($notification);

        if (count($recepients) === 0) {
            throw new \Exception('No email recipients found');
        }

        $mailMessage = $notification->toMail($notifiable);
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from(
                    data_get($notifiable, 'smtp_from_address'),
                    data_get($notifiable, 'smtp_from_name'),
                )
                ->bcc($recepients)
                ->subject($mailMessage->subject)
                ->html((string)$mailMessage->render())
        );
    }

    private function bootConfigs($notifiable): void
    {
        $password = data_get($notifiable, 'smtp_password');
        if ($password) $password = decrypt($password);

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            "transport" => "smtp",
            "host" => data_get($notifiable, 'smtp_host'),
            "port" => data_get($notifiable, 'smtp_port'),
            "encryption" => data_get($notifiable, 'smtp_encryption'),
            "username" => data_get($notifiable, 'smtp_username'),
            "password" => $password,
            "timeout" => data_get($notifiable, 'smtp_timeout'),
            "local_domain" => null,
        ]);
    }
}