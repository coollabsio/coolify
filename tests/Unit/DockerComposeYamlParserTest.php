<?php

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

it('parses Docker Compose document markers and multiline anchors', function () {
    $compose = <<<'YAML'
# Airflow-style Docker Compose file
---
x-airflow-common:
  &airflow-common
  image: apache/airflow:2.9.2
  environment:
    &airflow-common-env
    AIRFLOW__CORE__EXECUTOR: CeleryExecutor
services:
  airflow-webserver:
    <<: *airflow-common
    environment:
      <<: *airflow-common-env
      AIRFLOW__CORE__LOAD_EXAMPLES: 'false'
YAML;

    $parsed = parseDockerComposeYaml($compose);

    expect($parsed['services']['airflow-webserver']['image'])
        ->toBe('apache/airflow:2.9.2')
        ->and($parsed['services']['airflow-webserver']['environment']['AIRFLOW__CORE__EXECUTOR'])
        ->toBe('CeleryExecutor')
        ->and($parsed['services']['airflow-webserver']['environment']['AIRFLOW__CORE__LOAD_EXAMPLES'])
        ->toBe('false');
});

it('still rejects Docker Compose input containing multiple documents', function () {
    expect(fn () => parseDockerComposeYaml("services: {}\n---\nservices: {}\n"))
        ->toThrow(ParseException::class, 'Multiple documents are not supported');
});

it('preserves standard Docker Compose parsing', function () {
    $compose = "services:\n  web:\n    image: nginx\n";

    expect(parseDockerComposeYaml($compose))->toBe(Yaml::parse($compose));
});

it('rejects standalone anchors without deeper indentation', function () {
    $compose = "services:\n&common\n  web:\n    image: nginx\n";

    expect(fn () => parseDockerComposeYaml($compose))->toThrow(ParseException::class);
});

it('preserves anchor-like content inside block scalars', function () {
    $compose = <<<'YAML'
---
x-common:
  &common
  image: nginx
services:
  web:
    <<: *common
    command: |
      key:
        &literal
YAML;

    expect(parseDockerComposeYaml($compose)['services']['web']['command'])
        ->toBe("key:\n  &literal");
});
