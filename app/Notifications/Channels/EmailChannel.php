<?php

namespace App\Notifications\Channels;

use App\Services\ConfigurationRepository;
use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class EmailChannel
{
    private ConfigurationRepository $configRepository;

    public function __construct(ConfigurationRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }

    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        try {
            $this->bootConfigs($notifiable);
            $recipients = $notifiable->getRecipients();
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
            if (blank($type)) {
                throw new Exception('No email settings found.');
            }

            return;
        }

        $this->configRepository->updateMailConfig($emailSettings);
    }
}
