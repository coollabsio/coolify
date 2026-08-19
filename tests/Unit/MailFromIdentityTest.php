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

it('treats a blank from name as missing instead of sending an unnamed address', function () {
    $identity = mail_from_identity((object) [
        'smtp_from_address' => 'admin@example.com',
        'smtp_from_name' => '   ',
    ]);

    expect($identity['name'])->not->toBeEmpty()
        ->and($identity['name'])->not->toBe('admin');
});
