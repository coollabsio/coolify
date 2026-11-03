<?php

use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

function is_transactional_emails_enabled(): bool
{
    $settings = instanceSettings();

    return $settings->smtp_enabled || $settings->resend_enabled;
}

/**
 * The sender of an email. With $asNotification, the optional notification sender of the
 * instance settings replaces the default sender, so alerts do not share its reputation.
 *
 * @return array{address: string, name: string}
 */
function mail_from_identity(object $settings, bool $asNotification = false): array
{
    if ($asNotification && filled($settings->notification_from_address ?? null)) {
        $address = (string) $settings->notification_from_address;
        $name = trim((string) ($settings->notification_from_name ?? ''));
    } else {
        if (blank($settings->smtp_from_address ?? null)) {
            throw new InvalidArgumentException('Transactional email sender address is not configured.');
        }

        $address = (string) $settings->smtp_from_address;
        $name = '';
    }

    if ($name === '') {
        $name = trim((string) ($settings->smtp_from_name ?? ''));
    }

    return [
        'address' => $address,
        'name' => $name !== '' ? $name : 'Coolify',
    ];
}

function mail_from_address(object $settings, bool $asNotification = false): Address
{
    $identity = mail_from_identity($settings, $asNotification);

    return new Address($identity['address'], $identity['name']);
}

function mail_from_formatted(object $settings, bool $asNotification = false): string
{
    return mail_from_address($settings, $asNotification)->toString();
}

function mail_from_email(Email $email, object $settings, bool $asNotification = false): Email
{
    $email->from(mail_from_address($settings, $asNotification));
    prevent_mail_from_header_folding($email, $settings);

    return $email;
}

function mail_from_message(Message $message, object $settings, bool $asNotification = false): Message
{
    $identity = mail_from_identity($settings, $asNotification);
    $message->from($identity['address'], $identity['name']);
    prevent_mail_from_header_folding($message->getSymfonyMessage(), $settings);

    return $message;
}

function prevent_mail_from_header_folding(Email $email, object $settings): void
{
    if (strtolower(trim((string) ($settings->smtp_host ?? ''))) !== 'smtp.protonmail.ch') {
        return;
    }

    $email->getHeaders()->get('From')?->setMaxLineLength(998);
}

function send_internal_notification(string $message): void
{
    try {
        $team = Team::find(0);
        $team?->notify(new GeneralNotification($message));
    } catch (Throwable) {
    }
}

/**
 * Send an email through the instance email settings. Use $asNotification for alerts, so they
 * come from the notification sender when one is set.
 */
function send_user_an_email(MailMessage $mail, string $email, ?string $cc = null, bool $asNotification = false): void
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
            fn (Message $message) => mail_from_message($message, $settings, $asNotification)
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
            fn (Message $message) => mail_from_message($message, $settings, $asNotification)
                ->to($email)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    }
}

function set_transanctional_email_settings($settings = null)
{
    if (! $settings) {
        $settings = instanceSettings();
    }
    if (! data_get($settings, 'smtp_enabled') && ! data_get($settings, 'resend_enabled')) {
        return null;
    }

    $configRepository = app('App\Services\ConfigurationRepository'::class);
    $configRepository->updateMailConfig($settings);

    if (data_get($settings, 'resend_enabled')) {
        return 'resend';
    }

    if (data_get($settings, 'smtp_enabled')) {
        return 'smtp';
    }

    return null;
}
