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
                $response = $resend->emails->send([
                    'from' => mail_from_formatted($settings),
                    'to' => $recipients,
                    'subject' => $mailMessage->subject,
                    'html' => (string) $mailMessage->render(),
                ]);

                // The Resend SDK only throws for error names it knows; any other API error is returned as a response.
                if (blank($response->getAttribute('id'))) {
                    $error = $response->toArray();
                    $error['message'] = $error['message'] ?? 'Resend did not accept the email.';

                    throw new ErrorException($error);
                }
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
            ['status' => $statusCode, 'name' => $errorName] = self::resendErrorDetails($e);

            // Map HTTP status codes and Resend error names to user-friendly messages.
            // An invalid key comes back as 401 "validation_error"; an unverified domain as 403 "validation_error".
            $userMessage = match (true) {
                $errorName === 'restricted_api_key', $statusCode === 401 && $errorName === null => 'Your Resend API key has restricted permissions. Please use an API key with Full Access permissions.',
                $statusCode === 401, $statusCode === 403 && $errorName !== 'validation_error' => 'Invalid Resend API key. Please verify your API key in the Resend dashboard and update it in settings.',
                in_array($statusCode, [400, 422], true), $errorName === 'validation_error' => 'Email validation failed: '.$e->getErrorMessage(),
                $statusCode === 429 && in_array($errorName, [null, 'rate_limit_exceeded'], true) => 'Resend rate limit exceeded. Please try again in a few minutes.',
                default => 'Failed to send email via Resend: '.$e->getErrorMessage(),
            };

            // Log detailed error for admin debugging (redact sensitive data)
            $emailSettings = $notifiable->emailNotificationSettings ?? instanceSettings();
            data_set($emailSettings, 'smtp_password', '********');
            data_set($emailSettings, 'resend_api_key', '********');

            send_internal_notification(sprintf(
                "Resend Error\nStatus Code: %s\nMessage: %s\nNotification: %s\nEmail Settings:\n%s",
                $statusCode,
                $e->getErrorMessage(),
                get_class($notification),
                json_encode($emailSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ));

            // Don't report expected errors (invalid keys, validation) to Sentry
            if (in_array($statusCode, [403, 401, 400, 422], true)) {
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

    /**
     * Read the status code and error name from a Resend error.
     *
     * getErrorCode() and getErrorType() fail when a body has neither "code"/"statusCode"
     * nor "type"/"name" (for example the legacy nested format), so read the raw contents.
     *
     * @return array{status: int, name: ?string}
     */
    private static function resendErrorDetails(ErrorException $e): array
    {
        $contents = (fn (): array => $this->contents)->call($e);

        return [
            'status' => (int) ($contents['code'] ?? $contents['statusCode'] ?? 0),
            'name' => isset($contents['name']) ? (string) $contents['name'] : null,
        ];
    }
}
