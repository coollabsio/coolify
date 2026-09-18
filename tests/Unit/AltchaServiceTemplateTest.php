<?php

it('includes an ALTCHA Sentinel one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/altcha.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('ghcr.io/altcha-org/sentinel:${ALTCHA_VERSION:-latest}')
        ->toContain('SERVICE_URL_ALTCHA_8080')
        ->toContain('altcha-data:/data')
        ->toContain("grep -q ':1F90 ' /proc/net/tcp /proc/net/tcp6")
        ->toContain("volumes:\n  altcha-data:")
        ->not->toContain('busybox wget');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('altcha');
        expect($templates['altcha']['port'] ?? null)->toBe('8080');
        expect($templates['altcha']['logo'] ?? null)->toBe('svgs/altcha.svg');
        expect($templates['altcha']['category'] ?? null)->toBe('security');

        $generatedCompose = base64_decode($templates['altcha']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/altcha-org/sentinel:${ALTCHA_VERSION:-latest}')
            ->toContain("grep -q ':1F90 ' /proc/net/tcp /proc/net/tcp6")
            ->toContain("volumes:\n  altcha-data:")
            ->toContain($templateFile === 'service-templates.json'
                ? 'SERVICE_FQDN_ALTCHA_8080'
                : 'SERVICE_URL_ALTCHA_8080');
    }
});
