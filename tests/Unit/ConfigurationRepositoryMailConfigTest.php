<?php

use App\Services\ConfigurationRepository;
use Illuminate\Config\Repository;

it('configures the laravel smtp scheme from encryption', function (string $mode, int $port, string $scheme, ?string $encryption, string $autoTls) {
    $config = new Repository;
    $repository = new ConfigurationRepository($config);

    $repository->updateMailConfig((object) [
        'resend_enabled' => false,
        'smtp_enabled' => true,
        'smtp_encryption' => $mode,
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => $port,
        'smtp_username' => 'user',
        'smtp_password' => 'secret',
        'smtp_timeout' => null,
        'smtp_from_address' => 'from@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    expect($config->get('mail.mailers.smtp.scheme'))->toBe($scheme)
        ->and($config->get('mail.mailers.smtp.encryption'))->toBe($encryption)
        ->and($config->get('mail.mailers.smtp.auto_tls'))->toBe($autoTls);
})->with([
    'none on port 465' => ['none', 465, 'smtp', null, '0'],
    'tls on a non-465 port' => ['tls', 587, 'smtps', 'tls', ''],
]);

it('preserves the configured smtp ehlo domain', function () {
    $config = new Repository([
        'mail' => [
            'mailers' => [
                'smtp' => ['local_domain' => 'coolify.example.com'],
            ],
        ],
    ]);
    $repository = new ConfigurationRepository($config);

    $repository->updateMailConfig((object) [
        'resend_enabled' => false,
        'smtp_enabled' => true,
        'smtp_encryption' => 'starttls',
        'smtp_host' => 'smtp-relay.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => null,
        'smtp_password' => null,
        'smtp_timeout' => null,
        'smtp_from_address' => 'from@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    expect($config->get('mail.mailers.smtp.local_domain'))->toBe('coolify.example.com');
});
