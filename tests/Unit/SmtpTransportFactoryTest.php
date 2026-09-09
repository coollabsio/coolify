<?php

use App\Support\SmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

function smtpSettings(array $overrides = []): object
{
    return (object) array_merge([
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 25,
        'smtp_encryption' => 'none',
        'smtp_username' => 'user',
        'smtp_password' => 'secret',
        'smtp_timeout' => null,
    ], $overrides);
}

it('disables opportunistic STARTTLS when encryption is none', function () {
    $transport = SmtpTransportFactory::fromSettings(smtpSettings([
        'smtp_encryption' => 'none',
    ]));

    expect($transport->isAutoTls())->toBeFalse()
        ->and($transport->getStream())->toBeInstanceOf(SocketStream::class)
        ->and($transport->getStream()->isTLS())->toBeFalse();
});

it('does not issue STARTTLS for the issue 5877 anonymous port 25 relay', function () {
    $transport = SmtpTransportFactory::fromSettings(smtpSettings([
        'smtp_encryption' => 'none',
        'smtp_port' => 25,
        'smtp_username' => '',
        'smtp_password' => '',
    ]));

    expect($transport->isAutoTls())->toBeFalse()
        ->and($transport->getStream()->isTLS())->toBeFalse()
        ->and($transport->getStream()->getPort())->toBe(25)
        ->and($transport->getUsername())->toBe('')
        ->and($transport->getPassword())->toBe('');
});

it('does not enable implicit TLS on port 465 when encryption is none', function () {
    $transport = SmtpTransportFactory::fromSettings(smtpSettings([
        'smtp_encryption' => 'none',
        'smtp_port' => 465,
    ]));

    expect($transport->isAutoTls())->toBeFalse()
        ->and($transport->getStream()->isTLS())->toBeFalse();
});

it('keeps opportunistic STARTTLS when encryption is starttls', function () {
    $transport = SmtpTransportFactory::fromSettings(smtpSettings([
        'smtp_encryption' => 'starttls',
    ]));

    expect($transport->isAutoTls())->toBeTrue()
        ->and($transport->getStream()->isTLS())->toBeFalse();
});

it('uses implicit TLS when encryption is tls', function () {
    $transport = SmtpTransportFactory::fromSettings(smtpSettings([
        'smtp_encryption' => 'tls',
        'smtp_port' => 465,
    ]));

    expect($transport->isAutoTls())->toBeTrue()
        ->and($transport->getStream()->isTLS())->toBeTrue();
});

it('infers implicit TLS on port 465 when encryption is null', function () {
    $transport = SmtpTransportFactory::fromSettings(smtpSettings([
        'smtp_encryption' => null,
        'smtp_port' => 465,
    ]));

    expect($transport->getStream()->isTLS())->toBeTrue();
});

it('applies a configured SMTP timeout to the transport stream', function () {
    $transport = SmtpTransportFactory::fromSettings(smtpSettings([
        'smtp_timeout' => 15,
    ]));

    expect($transport->getStream()->getTimeout())->toBe(15.0);
});

it('applies the configured ehlo domain to the transport', function () {
    $transport = SmtpTransportFactory::fromSettings(
        smtpSettings(['smtp_ehlo_domain' => 'team.example.com']),
        'instance.example.com'
    );

    expect($transport->getLocalDomain())->toBe('team.example.com');
});

it('falls back to the instance ehlo domain for legacy smtp settings', function () {
    $transport = SmtpTransportFactory::fromSettings(
        smtpSettings(),
        'instance.example.com'
    );

    expect($transport->getLocalDomain())->toBe('instance.example.com');
});

it('maps none encryption to laravel mailer options that disable auto tls', function () {
    expect(SmtpTransportFactory::mailerOptions(smtpSettings([
        'smtp_encryption' => 'none',
    ])))->toBe([
        'scheme' => 'smtp',
        'encryption' => null,
        'auto_tls' => '0',
    ]);
});

it('maps encryption to laravel mailer options', function (?string $mode, ?string $scheme, ?string $encryption) {
    expect(SmtpTransportFactory::mailerOptions(smtpSettings([
        'smtp_encryption' => $mode,
    ])))->toBe([
        'scheme' => $scheme,
        'encryption' => $encryption,
        'auto_tls' => $mode === 'none' ? '0' : '',
    ]);
})->with([
    'none' => ['none', 'smtp', null],
    'starttls' => ['starttls', 'smtp', null],
    'tls' => ['tls', 'smtps', 'tls'],
    'unset' => [null, null, null],
]);
