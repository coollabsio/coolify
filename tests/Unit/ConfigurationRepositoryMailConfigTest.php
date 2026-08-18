<?php

use App\Services\ConfigurationRepository;
use Illuminate\Config\Repository;

it('disables auto tls in laravel mail config when smtp encryption is none', function () {
    $config = new Repository;
    $repository = new ConfigurationRepository($config);

    $repository->updateMailConfig((object) [
        'resend_enabled' => false,
        'smtp_enabled' => true,
        'smtp_encryption' => 'none',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 25,
        'smtp_username' => 'user',
        'smtp_password' => 'secret',
        'smtp_timeout' => null,
        'smtp_from_address' => 'from@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    expect($config->get('mail.mailers.smtp.encryption'))->toBeNull()
        ->and($config->get('mail.mailers.smtp.auto_tls'))->toBe('0');
});
