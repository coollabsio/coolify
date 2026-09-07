<?php

use Illuminate\Mail\Message;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

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

it('keeps the smtp from name and address on the same header line', function () {
    $email = mail_from_email(new Email, (object) [
        'smtp_host' => 'smtp.protonmail.ch',
        'smtp_from_address' => 'contact@advanceddigitalmarketingltda.com',
        'smtp_from_name' => 'Advanced Digital Marketing LTDA',
    ]);

    expect($email->getHeaders()->get('From')?->toString())
        ->toBe('From: Advanced Digital Marketing LTDA <contact@advanceddigitalmarketingltda.com>');
});

it('keeps the Laravel mail from name and address on the same header line', function () {
    $message = mail_from_message(new Message(new Email), (object) [
        'smtp_host' => 'smtp.protonmail.ch',
        'smtp_from_address' => 'contact@advanceddigitalmarketingltda.com',
        'smtp_from_name' => 'Advanced Digital Marketing LTDA',
    ]);

    expect($message->getSymfonyMessage()->getHeaders()->get('From')?->toString())
        ->toBe('From: Advanced Digital Marketing LTDA <contact@advanceddigitalmarketingltda.com>');
});

it('keeps Symfony header folding for other smtp providers', function () {
    $email = mail_from_email(new Email, (object) [
        'smtp_host' => 'smtp.example.com',
        'smtp_from_address' => 'contact@advanceddigitalmarketingltda.com',
        'smtp_from_name' => 'Advanced Digital Marketing LTDA',
    ]);

    expect($email->getHeaders()->get('From')?->toString())
        ->toBe("From: Advanced Digital Marketing LTDA\r\n <contact@advanceddigitalmarketingltda.com>");
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
