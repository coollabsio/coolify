<?php

use App\Models\EnvironmentVariable;

it('stores assigned values with the encrypted cast', function () {
    $environmentVariable = new EnvironmentVariable;
    $environmentVariable->value = 'smtp';

    expect($environmentVariable->getAttributes()['value'])->toStartWith('eyJpdiI6');
    expect($environmentVariable->value)->toBe('smtp');
});
