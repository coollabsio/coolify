<?php

namespace App\Support;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class SmtpTransportFactory
{
    public static function fromSettings(object $settings, ?string $localDomain = null): EsmtpTransport
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

        $localDomain = $settings->smtp_ehlo_domain ?? $localDomain;
        if ($localDomain !== null && $localDomain !== '') {
            $transport->setLocalDomain($localDomain);
        }

        $stream = $transport->getStream();
        if (isset($settings->smtp_timeout) && $stream instanceof SocketStream) {
            $stream->setTimeout((float) $settings->smtp_timeout);
        }

        return $transport;
    }

    /**
     * @return array{scheme: ?string, encryption: ?string, auto_tls: string}
     */
    public static function mailerOptions(object $settings): array
    {
        $mode = self::encryptionMode($settings);

        return [
            'scheme' => match ($mode) {
                'none', 'starttls' => 'smtp',
                'tls' => 'smtps',
                default => null,
            },
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
