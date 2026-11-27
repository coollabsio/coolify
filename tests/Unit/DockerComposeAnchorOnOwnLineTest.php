<?php

use Symfony\Component\Yaml\Exception\ParseException;
use Tests\TestCase;

uses(TestCase::class);

it('parses anchors that are on their own line below the key, as in the Airflow Compose file', function () {
    $compose = <<<'YAML'
    ---
    x-airflow-common:
      &airflow-common
      # The image line is a comment in the Airflow file.
      image: apache/airflow:3.1.3
      environment:
        &airflow-common-env # The environment of all Airflow services.
        AIRFLOW__CORE__EXECUTOR: CeleryExecutor
      depends_on:
        &airflow-common-depends-on
        redis:
          condition: service_healthy
    services:
      airflow-apiserver:
        <<: *airflow-common
        command: api-server
      airflow-init:
        <<: *airflow-common
        environment:
          <<: *airflow-common-env
          _AIRFLOW_DB_MIGRATE: 'true'
        depends_on:
          <<: *airflow-common-depends-on
      redis:
        image: redis:7.2-bookworm
    YAML;

    $parsed = parseDockerComposeYaml($compose);

    expect($parsed['services']['airflow-apiserver'])->toBe([
        'image' => 'apache/airflow:3.1.3',
        'environment' => ['AIRFLOW__CORE__EXECUTOR' => 'CeleryExecutor'],
        'depends_on' => ['redis' => ['condition' => 'service_healthy']],
        'command' => 'api-server',
    ])
        ->and($parsed['services']['airflow-init']['environment'])->toBe([
            'AIRFLOW__CORE__EXECUTOR' => 'CeleryExecutor',
            '_AIRFLOW_DB_MIGRATE' => 'true',
        ])
        ->and($parsed['services']['airflow-init']['depends_on'])->toBe(['redis' => ['condition' => 'service_healthy']]);

    validateDockerComposeForInjection($compose);
});

it('parses an anchor on its own line before a sequence', function () {
    $compose = "x-ports:\n  &ports\n  - '80:80'\nservices:\n  web:\n    image: nginx\n    ports: *ports\n";

    expect(parseDockerComposeYaml($compose)['services']['web']['ports'])->toBe(['80:80']);
});

it('keeps a block scalar that has an anchor-like line unchanged', function () {
    $compose = "services:\n  web:\n    image: nginx\n    command: |\n      key:\n        &not-an-anchor\n";

    expect(parseDockerComposeYaml($compose)['services']['web']['command'])->toBe("key:\n  &not-an-anchor\n");
});

it('reports the error of the unchanged file when the YAML is still invalid', function () {
    $compose = "services:\n  web:\n    &anchor\n    image: nginx\n    command: [unclosed\n";

    expect(fn () => parseDockerComposeYaml($compose))
        ->toThrow(ParseException::class, 'Mapping values are not allowed in multi-line blocks at line 3 (near "&anchor")');
});
