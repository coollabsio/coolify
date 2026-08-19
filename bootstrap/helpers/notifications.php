<?php

use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;

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
    return (string) mail_from_address($settings);
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
    $from = mail_from_identity($settings);

    if ($cc) {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from($from['address'], $from['name'])
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
                ->from($from['address'], $from['name'])
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
