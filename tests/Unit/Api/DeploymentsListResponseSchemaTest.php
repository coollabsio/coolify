<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Regression test for #5874.
 *
 * The OpenAPI annotation on DeployController::get_application_deployments
 * declared its 200 response as an array of `Application` objects, but the
 * endpoint actually returns `ApplicationDeploymentQueue` records (deployments
 * have fields like `logs`, `commit`, `deployment_uuid` that do not exist on
 * `Application`).
 *
 * Clients generated from the OpenAPI spec therefore decoded the response into
 * the wrong type. The test verifies the checked-in `openapi.yaml` and
 * `openapi.json` documents reference the correct schema for this path.
 */
it('documents /deployments/applications/{uuid} as returning ApplicationDeploymentQueue items', function () {
    $path = '/deployments/applications/{uuid}';

    $root = dirname(__DIR__, 3);
    $yamlPath = $root.'/openapi.yaml';
    $jsonPath = $root.'/openapi.json';

    expect(is_file($yamlPath))->toBeTrue("Expected {$yamlPath} to exist");
    expect(is_file($jsonPath))->toBeTrue("Expected {$jsonPath} to exist");

    $yaml = Yaml::parseFile($yamlPath);
    $json = json_decode(file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);

    $yamlRef = data_get($yaml, "paths.{$path}.get.responses.200.content.application/json.schema.items.\$ref");
    $jsonRef = data_get($json, "paths.{$path}.get.responses.200.content.application/json.schema.items.\$ref");

    expect($yamlRef)->toBe('#/components/schemas/ApplicationDeploymentQueue');
    expect($jsonRef)->toBe('#/components/schemas/ApplicationDeploymentQueue');
});
