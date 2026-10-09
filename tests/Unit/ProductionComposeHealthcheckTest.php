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

it('checks the configured Postgres database in exec form so no argument is dropped', function (string $composeFile) {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/'.$composeFile);

    expect($compose['services']['postgres']['healthcheck']['test'])
        ->toBe(['CMD', 'pg_isready', '-U', '${DB_USERNAME}', '-d', '${DB_DATABASE:-coolify}']);
})->with([
    'stable' => 'docker-compose.prod.yml',
    'nightly' => 'other/nightly/docker-compose.prod.yml',
    'windows' => 'docker-compose.windows.yml',
    'nightly windows' => 'other/nightly/docker-compose.windows.yml',
]);
