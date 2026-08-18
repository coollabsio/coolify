<?php

namespace App\Support;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpTransportFactory
{
    public static function fromSettings(object $settings): EsmtpTransport
    {
        $mode = self::encryptionMode($settings);

        $transport = new EsmtpTransport(
            $settings->smtp_host,
            (int) $settings->smtp_port,
            match ($mode) {
                'none' => false,
                'tls' => true,
                default => null,
            }
        );

        if ($mode === 'none') {
            $transport->setAutoTls(false);
        }

        $transport->setUsername($settings->smtp_username ?? '');
        $transport->setPassword($settings->smtp_password ?? '');

        return $transport;
    }

    /**
     * @return array{encryption: ?string, auto_tls: string}
     */
    public static function mailerOptions(object $settings): array
    {
        $mode = self::encryptionMode($settings);

        return [
            'encryption' => $mode === 'tls' ? 'tls' : null,
            'auto_tls' => $mode === 'none' ? '0' : '',
        ];
    }

    private static function encryptionMode(object $settings): ?string
    {
        return $settings->smtp_encryption === null
            ? null
            : strtolower((string) $settings->smtp_encryption);
    }
}
