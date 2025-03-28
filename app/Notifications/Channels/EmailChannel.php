<?php

namespace App\Notifications\Channels;

use App\Models\Team;
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
            $team = data_get($notifiable, 'id');
            $members = Team::find($team)->members;
            $mailerType = $this->bootConfigs($notifiable);

            $recipients = $notifiable->getRecipients();
            if (count($recipients) === 0) {
                throw new Exception('No email recipients found');
            }
            foreach ($recipients as $recipient) {
                // check if the recipient is part of the team
                if (! $members->contains('email', $recipient)) {
                    $emailSettings = $notifiable->emailNotificationSettings;
                    data_set($emailSettings, 'smtp_password', '********');
                    data_set($emailSettings, 'resend_api_key', '********');
                    send_internal_notification(sprintf(
                        "Recipient is not part of the team: %s\nTeam: %s\nNotification: %s\nNotifiable: %s\nMailer Type: %s\nEmail Settings:\n%s",
                        $recipient,
                        $team,
                        get_class($notification),
                        get_class($notifiable),
                        $mailerType,
                        json_encode($emailSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ));
                    throw new Exception('Recipient is not part of the team');
                }
            }

            $mailMessage = $notification->toMail($notifiable);

            Mail::mailer($mailerType)->send(
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

    private function bootConfigs($notifiable): string
    {
        $emailSettings = $notifiable->emailNotificationSettings;

        if ($emailSettings->use_instance_email_settings) {
            $type = set_transanctional_email_settings();
            if (blank($type)) {
                throw new Exception('No email settings found.');
            }

            return $type;
        }

        $this->configRepository->updateMailConfig($emailSettings);

        if ($emailSettings->resend_enabled) {
            return 'resend';
        }

        if ($emailSettings->smtp_enabled) {
            return 'smtp';
        }

        throw new Exception('No email settings found.');
    }
}
