<?php

use Symfony\Component\Mime\Address;

it('uses the configured transactional from name and address', function () {
    $identity = mail_from_identity((object) [
        'smtp_from_address' => 'admin@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    expect($identity['address'])->toBe('admin@example.com')
        ->and($identity['name'])->toBe('Coolify');
});

it('does not fall back to the email local part when a from name is set', function () {
    $address = mail_from_address((object) [
        'smtp_from_address' => 'admin@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    expect($address)->toBeInstanceOf(Address::class)
        ->and($address->getAddress())->toBe('admin@example.com')
        ->and($address->getName())->toBe('Coolify')
        ->and($address->getName())->not->toBe('admin');
});

it('formats the transactional sender for resend', function () {
    $formattedAddress = mail_from_formatted((object) [
        'smtp_from_address' => 'admin@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    expect($formattedAddress)->toBe('"Coolify" <admin@example.com>');
});

it('treats a blank from name as missing instead of sending an unnamed address', function () {
    $identity = mail_from_identity((object) [
        'smtp_from_address' => 'admin@example.com',
        'smtp_from_name' => '   ',
    ]);

    expect($identity['name'])->toBe('Coolify')
        ->and($identity['name'])->not->toBe('admin');
});

it('rejects enabled email settings without a from address', function () {
    mail_from_identity((object) [
        'smtp_enabled' => true,
        'smtp_from_address' => null,
        'smtp_from_name' => 'Coolify',
    ]);
})->throws(InvalidArgumentException::class, 'Transactional email sender address is not configured.');
