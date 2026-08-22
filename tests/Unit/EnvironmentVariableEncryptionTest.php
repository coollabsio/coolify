<?php

use App\Models\EnvironmentVariable;

function invokeEncryptPlaintextValueAttribute(EnvironmentVariable $environmentVariable): void
{
    $method = new ReflectionMethod(EnvironmentVariable::class, 'encryptPlaintextValueAttribute');
    $method->invoke($environmentVariable);
}

it('stores assigned values with the encrypted cast', function () {
    $environmentVariable = new EnvironmentVariable;
    $environmentVariable->value = 'smtp';

    expect($environmentVariable->getAttributes()['value'])->toStartWith('eyJpdiI6');
    expect($environmentVariable->value)->toBe('smtp');
});

it('encrypts raw plaintext attributes before save', function () {
    $environmentVariable = new EnvironmentVariable;
    $environmentVariable->setRawAttributes(['value' => '  smtp  ']);

    invokeEncryptPlaintextValueAttribute($environmentVariable);

    expect($environmentVariable->getAttributes()['value'])->toStartWith('eyJpdiI6');
    expect($environmentVariable->value)->toBe('smtp');
});

it('does not re-encrypt an existing laravel payload', function () {
    $payload = encrypt('keep-me');
    $environmentVariable = new EnvironmentVariable;
    $environmentVariable->setRawAttributes(['value' => $payload]);

    invokeEncryptPlaintextValueAttribute($environmentVariable);

    expect($environmentVariable->getAttributes()['value'])->toBe($payload);
    expect($environmentVariable->value)->toBe('keep-me');
});
