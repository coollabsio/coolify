<?php

use App\Models\Service;

it('generates a valid mysql database name for DATABASE command', function () {
    $service = new Service([
        'name' => 'Copiservi CRM',
        'uuid' => 'test-uuid',
    ]);

    $value = generateEnvValue('DATABASE', $service);

    expect($value)
        ->toBeString()
        ->not->toBeEmpty()
        ->toMatch('/^[a-z][a-z0-9_]*$/')
        ->and(strlen($value))->toBeLessThanOrEqual(63);
});

