<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;

function is_transactional_emails_enabled(): bool
{
    $settings = instanceSettings();

    return $settings->smtp_enabled || $settings->resend_enabled;
}

function send_internal_notification(string $message): void
{
    try {
        $team = Team::find(0);
        $team?->notify(new GeneralNotification($message));
    } catch (\Throwable $e) {
        ray($e->getMessage());
    }
}

function send_user_an_email(MailMessage $mail, string $email, ?string $cc = null): void
{
    $settings = instanceSettings();
    $type = set_transanctional_email_settings($settings);
    if (blank($type)) {
        throw new Exception('No email settings found.');
    }
    if ($cc) {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->replyTo($email)
                ->cc($cc)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    } else {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    }
}

function set_transanctional_email_settings(?InstanceSettings $settings = null): ?string // returns null|resend|smtp and defaults to array based on mail.php config
{
    if (! $settings) {
        $settings = instanceSettings();
    }
    if (! data_get($settings, 'smtp_enabled') && ! data_get($settings, 'resend_enabled')) {
        return null;
    }

    if (data_get($settings, 'resend_enabled')) {
        config()->set('mail.default', 'resend');
        config()->set('mail.from.address', data_get($settings, 'smtp_from_address'));
        config()->set('mail.from.name', data_get($settings, 'smtp_from_name'));
        config()->set('resend.api_key', data_get($settings, 'resend_api_key'));

        return 'resend';
    }

    $encryption = match (strtolower(data_get($settings, 'smtp_encryption'))) {
        'starttls' => null,
        'tls' => 'tls',
        'none' => null,
        default => null,
    };

    if (data_get($settings, 'smtp_enabled')) {
        config()->set('mail.from.address', data_get($settings, 'smtp_from_address'));
        config()->set('mail.from.name', data_get($settings, 'smtp_from_name'));
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => data_get($settings, 'smtp_host'),
            'port' => data_get($settings, 'smtp_port'),
            'encryption' => $encryption,
            'username' => data_get($settings, 'smtp_username'),
            'password' => data_get($settings, 'smtp_password'),
            'timeout' => data_get($settings, 'smtp_timeout'),
            'local_domain' => null,
            'auto_tls' => data_get($settings, 'smtp_encryption') === 'none' ? '0' : '', // If encryption is "none", it will not try to upgrade to TLS via StartTLS to make sure it is unencrypted.
        ]);

        return 'smtp';
    }
}
