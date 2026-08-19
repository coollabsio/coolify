<?php

use App\Models\EnvironmentVariable;

function invokeGetEnvironmentVariables(?string $value): ?string
{
    $method = new ReflectionMethod(EnvironmentVariable::class, 'get_environment_variables');

    return $method->invoke(new EnvironmentVariable, $value);
}

it('returns null when the stored value is empty', function () {
    expect(invokeGetEnvironmentVariables(null))->toBeNull();
    expect(invokeGetEnvironmentVariables(''))->toBeNull();
});

it('decrypts a valid encrypted payload', function () {
    $plain = 'smtp.example.test';

    expect(invokeGetEnvironmentVariables(encrypt($plain)))->toBe($plain);
});

it('returns the raw value when decrypt fails', function (string $raw) {
    expect(invokeGetEnvironmentVariables($raw))->toBe(trim($raw));
})->with([
    'plain mailer' => 'smtp',
    'port' => '587',
    'email' => 'ops@example.test',
    'padded' => '  smtp  ',
]);
