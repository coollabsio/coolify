<?php

use App\Support\SmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

function smtpConnectSettings(string $host): object
{
    return (object) [
        'smtp_host' => $host,
        'smtp_port' => 587,
        'smtp_encryption' => 'starttls',
        'smtp_username' => 'user',
        'smtp_password' => 'secret',
        'smtp_timeout' => null,
    ];
}

it('connects to the checked address and verifies TLS for the normalized host name', function (string $storedHost) {
    $stream = SmtpTransportFactory::fromSettings(smtpConnectSettings($storedHost), connectAddress: '93.184.216.34')->getStream();

    expect($stream)->toBeInstanceOf(SocketStream::class)
        ->and($stream->getHost())->toBe('93.184.216.34')
        ->and($stream->getStreamOptions())->toBe(['ssl' => ['peer_name' => 'smtp.example.com']]);
})->with(['smtp.example.com', 'smtp.example.com.', ' SMTP.Example.COM ']);

it('brackets a checked IPv6 address', function () {
    $stream = SmtpTransportFactory::fromSettings(smtpConnectSettings('smtp.example.com'), connectAddress: '2606:4700::1111')->getStream();

    expect($stream->getHost())->toBe('[2606:4700::1111]');
});

it('keeps an IP address host without a peer name', function () {
    $stream = SmtpTransportFactory::fromSettings(smtpConnectSettings('[2606:4700::1111]'), connectAddress: '2606:4700::1111')->getStream();

    expect($stream->getHost())->toBe('[2606:4700::1111]')
        ->and($stream->getStreamOptions())->toBe([]);
});
