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
 * @return array{address: string, name: string}
 */
function mail_from_identity(object $settings): array
{
    if (blank($settings->smtp_from_address ?? null)) {
        throw new InvalidArgumentException('Transactional email sender address is not configured.');
    }

    $address = (string) $settings->smtp_from_address;

    $name = trim((string) ($settings->smtp_from_name ?? ''));

    return [
        'address' => $address,
        'name' => $name !== '' ? $name : 'Coolify',
    ];
}

function mail_from_address(object $settings): Address
{
    $identity = mail_from_identity($settings);

    return new Address($identity['address'], $identity['name']);
}

function mail_from_formatted(object $settings): string
{
    return mail_from_address($settings)->toString();
}

function mail_from_email(Email $email, object $settings): Email
{
    $email->from(mail_from_address($settings));
    prevent_mail_from_header_folding($email, $settings);

    return $email;
}

function mail_from_message(Message $message, object $settings): Message
{
    $identity = mail_from_identity($settings);
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
            fn (Message $message) => mail_from_message($message, $settings)
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
            fn (Message $message) => mail_from_message($message, $settings)
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
