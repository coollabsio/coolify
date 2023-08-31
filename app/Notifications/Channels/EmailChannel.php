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
        if ($this->isResend) {
            foreach($recepients as $receipient) {
                Mail::send(
                    [],
                    [],
                    fn (Message $message) => $message
                        ->from(
                            data_get($notifiable, 'smtp_from_address'),
                            data_get($notifiable, 'smtp_from_name'),
                        )
                        ->to($receipient)
                        ->subject($mailMessage->subject)
                        ->html((string)$mailMessage->render())
                );
            }

        } else {
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

    }

    private function bootConfigs($notifiable): void
    {
        if (data_get($notifiable, 'resend_enabled')) {
            $resendAPIKey = data_get($notifiable, 'resend_api_key');
            if ($resendAPIKey) {
                $this->isResend = true;
                config()->set('mail.default', 'resend');
                config()->set('resend.api_key', $resendAPIKey);
            }
        }
        if (data_get($notifiable, 'smtp_enabled')) {
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
}
