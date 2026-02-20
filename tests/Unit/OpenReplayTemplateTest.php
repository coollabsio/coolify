<?php

use Symfony\Component\Yaml\Yaml;

it('keeps the openreplay compose template parseable with core services', function () {
    $templatePath = base_path('templates/compose/openreplay.yaml');

    expect(is_file($templatePath))->toBeTrue();

    $content = file_get_contents($templatePath);
    expect($content)->toContain('# documentation:')
        ->toContain('# slogan:')
        ->toContain('# category: analytics')
        ->toContain('# logo: svgs/openreplay.svg')
        ->toContain('SERVICE_FQDN_NGINXOPENREPLAY');

    $compose = Yaml::parse($content);

    expect($compose)->toBeArray()
        ->and($compose)->toHaveKey('services');

    $serviceNames = array_keys($compose['services']);
    expect($serviceNames)->toContain(
        'postgresql',
        'clickhouse',
        'redis',
        'minio',
        'frontend-openreplay',
        'nginx-openreplay'
    );

    $nginxEnvironment = $compose['services']['nginx-openreplay']['environment'] ?? [];
    expect($nginxEnvironment)->toContain('SERVICE_URL_NGINXOPENREPLAY');
});

it('keeps the openreplay svg valid xml', function () {
    $svgPath = base_path('public/svgs/openreplay.svg');

    expect(is_file($svgPath))->toBeTrue();

    libxml_use_internal_errors(true);
    $svg = simplexml_load_string(file_get_contents($svgPath));

    expect($svg)->not->toBeFalse()
        ->and($svg->getName())->toBe('svg');

    libxml_clear_errors();
});
