<?php

use Symfony\Component\Yaml\Yaml;

it('allows Coolify enough time to start before counting healthcheck failures', function (string $composeFile) {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/'.$composeFile);

    expect($compose['services']['coolify']['healthcheck'])
        ->start_period->toBe('1m')
        ->interval->toBe('5s')
        ->retries->toBe(24);
})->with([
    'stable' => 'docker-compose.prod.yml',
    'nightly' => 'other/nightly/docker-compose.prod.yml',
]);
