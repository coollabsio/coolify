<?php

namespace App\Notifications\Channels;

use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class EmailChannel
{
    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        try {
            $this->bootConfigs($notifiable);
            $recipients = $notifiable->getRecepients($notification);
            if (count($recipients) === 0) {
                throw new Exception('No email recipients found');
            }

            $mailMessage = $notification->toMail($notifiable);
            Mail::send(
                [],
                [],
                fn (Message $message) => $message
                    ->to($recipients)
                    ->subject($mailMessage->subject)
                    ->html((string) $mailMessage->render())
            );
        } catch (Exception $e) {
            $error = $e->getMessage();
            if ($error === 'No email settings found.') {
                throw $e;
            }
            ray($e->getMessage());
            $message = "EmailChannel error: {$e->getMessage()}. Failed to send email to:";
            if (isset($recipients)) {
                $message .= implode(', ', $recipients);
            }
            if (isset($mailMessage)) {
                $message .= " with subject: {$mailMessage->subject}";
            }
            send_internal_notification($message);
            throw $e;
        }
    }

    private function bootConfigs($notifiable): void
    {
        if (data_get($notifiable, 'use_instance_email_settings')) {
            $type = set_transanctional_email_settings();
            if (! $type) {
                throw new Exception('No email settings found.');
            }

            return;
        }
        config()->set('mail.from.address', data_get($notifiable, 'smtp_from_address', 'test@example.com'));
        config()->set('mail.from.name', data_get($notifiable, 'smtp_from_name', 'Test'));
        if (data_get($notifiable, 'resend_enabled')) {
            config()->set('mail.default', 'resend');
            config()->set('resend.api_key', data_get($notifiable, 'resend_api_key'));
        }
        if (data_get($notifiable, 'smtp_enabled')) {
            config()->set('mail.default', 'smtp');
            config()->set('mail.mailers.smtp', [
                'transport' => 'smtp',
                'host' => data_get($notifiable, 'smtp_host'),
                'port' => data_get($notifiable, 'smtp_port'),
                'encryption' => data_get($notifiable, 'smtp_encryption'),
                'username' => data_get($notifiable, 'smtp_username'),
                'password' => data_get($notifiable, 'smtp_password'),
                'timeout' => data_get($notifiable, 'smtp_timeout'),
                'local_domain' => null,
            ]);
        }
    }
}
