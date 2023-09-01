<?php

namespace App\Notifications\Channels;

use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class EmailChannel
{
    private bool $isResend = false;
    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        $this->bootConfigs($notifiable);
        $recepients = $notifiable->getRecepients($notification);

        if (count($recepients) === 0) {
            throw new Exception('No email recipients found');
        }

        $mailMessage = $notification->toMail($notifiable);
        // if ($this->isResend) {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from(
                    data_get($notifiable, 'smtp_from_address'),
                    data_get($notifiable, 'smtp_from_name'),
                )
                ->to($recepients)
                ->subject($mailMessage->subject)
                ->html((string)$mailMessage->render())
        );
        // } else {
        //     Mail::send(
        //         [],
        //         [],
        //         fn (Message $message) => $message
        //             ->from(
        //                 data_get($notifiable, 'smtp_from_address'),
        //                 data_get($notifiable, 'smtp_from_name'),
        //             )
        //             ->bcc($recepients)
        //             ->subject($mailMessage->subject)
        //             ->html((string)$mailMessage->render())
        //     );
        // }
    }

    private function bootConfigs($notifiable): void
    {
        if (data_get($notifiable, 'use_instance_email_settings')) {
            $type = set_transanctional_email_settings();
            if (!$type) {
                throw new Exception('No email settings found.');
            }
            if ($type === 'resend') {
                $this->isResend = true;
            }
            return;
        }
        if (data_get($notifiable, 'resend_enabled')) {
            $this->isResend = true;
            config()->set('mail.default', 'resend');
            config()->set('resend.api_key', data_get($notifiable, 'resend_api_key'));
        }
        if (data_get($notifiable, 'smtp_enabled')) {
            config()->set('mail.default', 'smtp');
            config()->set('mail.mailers.smtp', [
                "transport" => "smtp",
                "host" => data_get($notifiable, 'smtp_host'),
                "port" => data_get($notifiable, 'smtp_port'),
                "encryption" => data_get($notifiable, 'smtp_encryption'),
                "username" => data_get($notifiable, 'smtp_username'),
                "password" => data_get($notifiable, 'smtp_password'),
                "timeout" => data_get($notifiable, 'smtp_timeout'),
                "local_domain" => null,
            ]);
        }
    }
}
