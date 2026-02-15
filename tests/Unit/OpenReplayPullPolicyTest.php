<?php

/**
 * Unit test to verify that OpenReplay service template has pull_policy: missing
 * configured for all services to prevent Docker rate limit errors.
 *
 * This test validates the fix for the issue where pulling multiple images
 * from AWS ECR Public and other registries would trigger rate limit errors.
 */
it('ensures openreplay service has pull_policy configured for all services', function () {
    $yamlContent = file_get_contents(__DIR__.'/../../templates/compose/openreplay.yaml');
    
    expect($yamlContent)
        ->toContain('pull_policy: missing');
    
    // Parse YAML to verify structure
    $yaml = \Symfony\Component\Yaml\Yaml::parse($yamlContent);
    $services = data_get($yaml, 'services', []);
    
    expect($services)
        ->toBeArray()
        ->not->toBeEmpty();
    
    // Verify each service has pull_policy set to 'missing'
    foreach ($services as $serviceName => $serviceConfig) {
        expect($serviceConfig)->toHaveKey('pull_policy');
        expect(data_get($serviceConfig, 'pull_policy'))
            ->toBe('missing', "Service '{$serviceName}' pull_policy should be 'missing'");
    }
});

it('ensures openreplay service template JSON contains updated compose with pull_policy', function () {
    $serviceTemplatesPath = __DIR__.'/../../templates/service-templates.json';
    $serviceTemplates = json_decode(file_get_contents($serviceTemplatesPath), true);
    
    expect($serviceTemplates)
        ->toHaveKey('openreplay');
    
    $openreplayTemplate = data_get($serviceTemplates, 'openreplay');
    
    expect($openreplayTemplate)
        ->toHaveKey('compose')
        ->and(data_get($openreplayTemplate, 'compose'))
        ->not->toBeEmpty();
    
    // Decode base64 compose
    $composeYaml = base64_decode(data_get($openreplayTemplate, 'compose'));
    
    expect($composeYaml)
        ->toContain('pull_policy: missing');
    
    // Parse and verify
    $yaml = \Symfony\Component\Yaml\Yaml::parse($composeYaml);
    $services = data_get($yaml, 'services', []);
    
    // Verify all services have pull_policy
    foreach ($services as $serviceName => $serviceConfig) {
        expect(data_get($serviceConfig, 'pull_policy'))
            ->toBe('missing', "Service '{$serviceName}' in JSON template should have pull_policy='missing'");
    }
});

it('ensures openreplay has expected number of services with pull_policy', function () {
    $yamlContent = file_get_contents(__DIR__.'/../../templates/compose/openreplay.yaml');
    $yaml = \Symfony\Component\Yaml\Yaml::parse($yamlContent);
    $services = data_get($yaml, 'services', []);
    
    // OpenReplay should have 22 services:
    // postgresql, clickhouse, redis, minio, nginx
    // and 17 AWS ECR services (alerts, api, http, images, integrations, sink,
    // sourcemapreader, spot, storage, assets, assist, canvases, chalice, db,
    // ender, frontend, heuristics)
    expect(count($services))->toBe(22);
    
    // Count services with pull_policy
    $servicesWithPullPolicy = array_filter($services, fn($service) => isset($service['pull_policy']));
    expect(count($servicesWithPullPolicy))->toBe(22);
});
