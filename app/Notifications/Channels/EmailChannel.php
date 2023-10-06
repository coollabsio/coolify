<?php

namespace App\Notifications\Channels;

use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Log;

class EmailChannel
{
    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        try {
            $this->bootConfigs($notifiable);
            $recepients = $notifiable->getRecepients($notification);
            if (count($recepients) === 0) {
                throw new Exception('No email recipients found');
            }

            $mailMessage = $notification->toMail($notifiable);
            Mail::send(
                [],
                [],
                fn (Message $message) => $message
                    ->to($recepients)
                    ->subject($mailMessage->subject)
                    ->html((string)$mailMessage->render())
            );
        } catch (Exception $e) {
            ray($e->getMessage());
            send_internal_notification("EmailChannel error: {$e->getMessage()}. Failed to send email to: " . implode(', ', $recepients) . " with subject: {$mailMessage->subject}");
            throw $e;
        }
    }

    private function bootConfigs($notifiable): void
    {
        if (data_get($notifiable, 'use_instance_email_settings')) {
            $type = set_transanctional_email_settings();
            if (!$type) {
                throw new Exception('No email settings found.');
            }
            return;
        }
        config()->set('mail.from.address', data_get($notifiable, 'smtp_from_address'));
        config()->set('mail.from.name', data_get($notifiable, 'smtp_from_name'));
        if (data_get($notifiable, 'resend_enabled')) {
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
