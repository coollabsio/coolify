<?php

namespace App\Notifications\Channels;

use App\Exceptions\NonReportableException;
use App\Models\Team;
use App\Support\SmtpTransportFactory;
use Exception;
use Illuminate\Notifications\Notification;
use Resend;
use Resend\Exceptions\ErrorException;
use Resend\Exceptions\TransporterException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

class EmailChannel
{
    public function __construct() {}

    public function send(SendsEmail $notifiable, Notification $notification): void
    {
        try {
            // Get team and validate membership before proceeding
            $team = data_get($notifiable, 'id');
            $members = Team::find($team)->members;

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

            // Validate team membership for all recipients
            if (count($recipients) === 0) {
                throw new Exception('No email recipients found');
            }

            // Skip team membership validation for test notifications
            $isTestNotification = data_get($notification, 'isTestNotification', false);

            if (! $isTestNotification) {
                foreach ($recipients as $recipient) {
                    // Check if the recipient is part of the team
                    if (! $members->contains('email', $recipient)) {
                        $emailSettings = $notifiable->emailNotificationSettings;
                        data_set($emailSettings, 'smtp_password', '********');
                        data_set($emailSettings, 'resend_api_key', '********');
                        send_internal_notification(sprintf(
                            "Recipient is not part of the team: %s\nTeam: %s\nNotification: %s\nNotifiable: %s\nEmail Settings:\n%s",
                            $recipient,
                            $team,
                            get_class($notification),
                            get_class($notifiable),
                            json_encode($emailSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        ));
                        throw new Exception('Recipient is not part of the team');
                    }
                }
            }

            $mailMessage = $notification->toMail($notifiable);

            if ($isResendEnabled) {
                $resend = Resend::client($settings->resend_api_key);
                $resend->emails->send([
                    'from' => mail_from_formatted($settings),
                    'to' => $recipients,
                    'subject' => $mailMessage->subject,
                    'html' => (string) $mailMessage->render(),
                ]);
            } elseif ($isSmtpEnabled) {
                $transport = SmtpTransportFactory::fromSettings(
                    $settings,
                    config('mail.mailers.smtp.local_domain')
                );
                $mailer = new Mailer($transport);

                $email = mail_from_email(new Email, $settings)
                    ->to(...$recipients)
                    ->subject($mailMessage->subject)
                    ->html((string) $mailMessage->render());

                $mailer->send($email);
            }
        } catch (ErrorException $e) {
            // Map HTTP status codes to user-friendly messages
            $userMessage = match ($e->getErrorCode()) {
                403 => 'Invalid Resend API key. Please verify your API key in the Resend dashboard and update it in settings.',
                401 => 'Your Resend API key has restricted permissions. Please use an API key with Full Access permissions.',
                429 => 'Resend rate limit exceeded. Please try again in a few minutes.',
                400 => 'Email validation failed: '.$e->getErrorMessage(),
                default => 'Failed to send email via Resend: '.$e->getErrorMessage(),
            };

            // Log detailed error for admin debugging (redact sensitive data)
            $emailSettings = $notifiable->emailNotificationSettings ?? instanceSettings();
            data_set($emailSettings, 'smtp_password', '********');
            data_set($emailSettings, 'resend_api_key', '********');

            send_internal_notification(sprintf(
                "Resend Error\nStatus Code: %s\nMessage: %s\nNotification: %s\nEmail Settings:\n%s",
                $e->getErrorCode(),
                $e->getErrorMessage(),
                get_class($notification),
                json_encode($emailSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ));

            // Don't report expected errors (invalid keys, validation) to Sentry
            if (in_array($e->getErrorCode(), [403, 401, 400])) {
                throw NonReportableException::fromException(new Exception($userMessage, $e->getCode(), $e));
            }

            throw new Exception($userMessage, $e->getCode(), $e);
        } catch (TransporterException $e) {
            send_internal_notification("Resend Transport Error: {$e->getMessage()}");
            throw new Exception('Unable to connect to Resend API. Please check your internet connection and try again.');
        } catch (\Throwable $e) {
            // Check if this is a Resend domain verification error on cloud instances
            if (isCloud() && str_contains($e->getMessage(), 'domain is not verified')) {
                // Throw as NonReportableException so it won't go to Sentry
                throw NonReportableException::fromException($e);
            }
            throw $e;
        }
    }
}
