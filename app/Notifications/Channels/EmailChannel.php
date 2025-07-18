<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Resend;

class EmailChannel
{
    public function __construct() {}

    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        $useInstanceEmailSettings = $notifiable->emailNotificationSettings->use_instance_email_settings;
        $isTransactionalEmail = data_get($notification, 'isTransactionalEmail', false);
        $customEmails = data_get($notification, 'emails', null);
        if ($useInstanceEmailSettings || $isTransactionalEmail) {
            $settings = instanceSettings();
        } else {
            $settings = $notifiable->emailNotificationSettings;
        }
        $isResendEnabled = $settings->resend_enabled;
        $isSmtpEnabled = $settings->smtp_enabled;
        if ($customEmails) {
            $recipients = [$customEmails];
        } else {
            $recipients = $notifiable->getRecipients();
        }
        $mailMessage = $notification->toMail($notifiable);

        if ($isResendEnabled) {
            $resend = Resend::client($settings->resend_api_key);
            $from = "{$settings->smtp_from_name} <{$settings->smtp_from_address}>";
            $resend->emails->send([
                'from' => $from,
                'to' => $recipients,
                'subject' => $mailMessage->subject,
                'html' => (string) $mailMessage->render(),
            ]);
        } elseif ($isSmtpEnabled) {
            $encryption = match (strtolower($settings->smtp_encryption)) {
                'starttls' => null,
                'tls' => 'tls',
                'none' => null,
                default => null,
            };

            $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                $settings->smtp_host,
                $settings->smtp_port,
                $encryption
            );
            $transport->setUsername($settings->smtp_username ?? '');
            $transport->setPassword($settings->smtp_password ?? '');

            $mailer = new \Symfony\Component\Mailer\Mailer($transport);

            $fromEmail = $settings->smtp_from_address ?? 'noreply@localhost';
            $fromName = $settings->smtp_from_name ?? 'System';
            $from = new \Symfony\Component\Mime\Address($fromEmail, $fromName);
            $email = (new \Symfony\Component\Mime\Email)
                ->from($from)
                ->to(...$recipients)
                ->subject($mailMessage->subject)
                ->html((string) $mailMessage->render());

            $mailer->send($email);
        }
    }
}
