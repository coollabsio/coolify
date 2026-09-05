<?php

it('includes a WordPress with OpenLiteSpeed one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/wordpress-with-openlitespeed.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('litespeedtech/openlitespeed:latest')
        ->toContain('mariadb:11')
        ->toContain('SERVICE_URL_WORDPRESS')
        ->toContain('WORDPRESS_DB_HOST=mariadb')
        ->toContain('wordpress-files:/var/www/vhosts/localhost/html')
        ->toContain('mariadb-data:/var/lib/mysql');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('wordpress-with-openlitespeed');
        expect($templates['wordpress-with-openlitespeed']['port'] ?? null)->toBe('80');
        expect($templates['wordpress-with-openlitespeed']['category'] ?? null)->toBe('cms');

        $generatedCompose = base64_decode($templates['wordpress-with-openlitespeed']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('litespeedtech/openlitespeed:latest')
            ->toContain($templateFile === 'service-templates.json'
                ? 'SERVICE_FQDN_WORDPRESS'
                : 'SERVICE_URL_WORDPRESS');
    }
});
