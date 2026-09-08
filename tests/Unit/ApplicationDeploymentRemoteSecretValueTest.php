<?php

use App\Jobs\ApplicationDeploymentJob;

it('quotes JSON remote secrets so compose treats their contents literally', function (string $value, string $expected) {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'format_remote_secret_value');

    expect($method->invoke($job, $value))->toBe($expected);
})->with([
    'object containing a variable reference' => ['{"password":"$ecret"}', '\'{"password":"$ecret"}\''],
    'array containing a comment marker' => ['["value # not a comment"]', '\'["value # not a comment"]\''],
    'object containing an apostrophe' => ['{"password":"it\'s $ecret"}', '"{\\"password\\":\\"it\'s $$ecret\\"}"'],
]);
