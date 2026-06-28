<?php

test('hex magic variables generate valid hex strings with expected lengths', function (string $command, int $expectedLength) {
    $value = generateEnvValue($command);

    expect($value)
        ->toBeString()
        ->toMatch('/^[0-9a-f]+$/');

    expect(strlen($value))->toBe($expectedLength);
})->with([
    'HEX_32' => ['HEX_32', 32],
    'HEX_64' => ['HEX_64', 64],
    'HEX_128' => ['HEX_128', 128],
]);

test('real base64 magic variables generate valid base64 strings from expected byte lengths', function (string $command, int $expectedBytes) {
    $value = generateEnvValue($command);
    $decodedValue = base64_decode($value, true);

    expect($value)->toBeString();
    expect($decodedValue)->not->toBeFalse();
    expect(strlen($decodedValue))->toBe($expectedBytes);
})->with([
    'REALBASE64' => ['REALBASE64', 32],
    'REALBASE64_32' => ['REALBASE64_32', 32],
    'REALBASE64_64' => ['REALBASE64_64', 64],
    'REALBASE64_128' => ['REALBASE64_128', 128],
]);
