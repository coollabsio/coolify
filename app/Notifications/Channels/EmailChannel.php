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
            $recipients = $notifiable->getRecipients($notification);
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
        $emailSettings = $notifiable->emailNotificationSettings;

        if ($emailSettings->use_instance_email_settings) {
            $type = set_transanctional_email_settings();
            if (! $type) {
                throw new Exception('No email settings found.');
            }

            return;
        }

        config()->set('mail.from.address', $emailSettings->smtp_from_address ?? 'test@example.com');
        config()->set('mail.from.name', $emailSettings->smtp_from_name ?? 'Test');

        if ($emailSettings->resend_enabled) {
            config()->set('mail.default', 'resend');
            config()->set('resend.api_key', $emailSettings->resend_api_key);
        }

        if ($emailSettings->smtp_enabled) {
            $encryption = match (strtolower($emailSettings->smtp_encryption)) {
                'starttls' => null,
                'tls' => 'tls',
                'none' => null,
                default => null,
            };

            config()->set('mail.default', 'smtp');
            config()->set('mail.mailers.smtp', [
                'transport' => 'smtp',
                'host' => $emailSettings->smtp_host,
                'port' => $emailSettings->smtp_port,
                'encryption' => $encryption,
                'username' => $emailSettings->smtp_username,
                'password' => $emailSettings->smtp_password,
                'timeout' => $emailSettings->smtp_timeout,
                'local_domain' => null,
                'auto_tls' => $emailSettings->smtp_encryption === 'none' ? '0' : '', // If encryption is "none", it will not try to upgrade to TLS via StartTLS to make sure it is unencrypted.
            ]);
        }
    }
}
