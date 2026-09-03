<?php

use App\Jobs\PushServerUpdateJob;

test('database status update declares a void return type', function () {
    $method = new ReflectionMethod(PushServerUpdateJob::class, 'updateDatabaseStatus');

    expect($method->getReturnType()?->getName())->toBe('void');
});
