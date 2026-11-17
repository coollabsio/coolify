<?php

namespace App\Support;

/**
 * SMTP settings of common email providers. A preset only fills the connection fields;
 * the user still enters the username and password from the provider.
 */
class SmtpPresets
{
    /**
     * @var array<string, array{label: string, host: string, port: int, encryption: 'starttls'|'tls', username?: string}>
     */
    public const PRESETS = [
        'postshiba' => ['label' => 'PostShiba', 'host' => 'smtp.postshiba.com', 'port' => 587, 'encryption' => 'starttls'],
        'amazon-ses' => ['label' => 'Amazon SES (us-east-1)', 'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'starttls'],
        'brevo' => ['label' => 'Brevo', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'starttls'],
        'gmail' => ['label' => 'Gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'starttls'],
        'mailgun' => ['label' => 'Mailgun', 'host' => 'smtp.mailgun.org', 'port' => 587, 'encryption' => 'starttls'],
        'mailjet' => ['label' => 'Mailjet', 'host' => 'in-v3.mailjet.com', 'port' => 587, 'encryption' => 'starttls'],
        'microsoft-365' => ['label' => 'Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls'],
        'postmark' => ['label' => 'Postmark', 'host' => 'smtp.postmarkapp.com', 'port' => 587, 'encryption' => 'starttls'],
        'proton-mail' => ['label' => 'Proton Mail', 'host' => 'smtp.protonmail.ch', 'port' => 587, 'encryption' => 'starttls'],
        'resend' => ['label' => 'Resend', 'host' => 'smtp.resend.com', 'port' => 465, 'encryption' => 'tls', 'username' => 'resend'],
        'sendgrid' => ['label' => 'SendGrid', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'starttls', 'username' => 'apikey'],
        'zoho' => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.com', 'port' => 465, 'encryption' => 'tls'],
    ];

    /**
     * Whether the host belongs to a provider of the presets. All of them need a username and password.
     */
    public static function requiresLogin(?string $host): bool
    {
        $host = strtolower(trim((string) $host));

        return $host !== '' && collect(self::PRESETS)->contains(fn (array $preset) => $preset['host'] === $host);
    }

    /**
     * Options for a listbox.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::PRESETS)
            ->map(fn (array $preset, string $key) => ['value' => $key, 'label' => $preset['label']])
            ->values()
            ->all();
    }
}
