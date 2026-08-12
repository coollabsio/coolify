<?php

it('includes a denokv one-click service template with lowercase key', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/denokv.yaml');

    expect($compose)
        ->toContain('ghcr.io/denoland/denokv:latest')
        ->toContain('SERVICE_URL_DENOKV_4512')
        ->toContain('# logo: svgs/denoKV.svg')
        ->toContain('ACCESS_TOKEN=${SERVICE_PASSWORD_DENOKV}');

    expect(file_exists(__DIR__.'/../../templates/compose/denoKV.yaml'))->toBeFalse();
    expect(file_exists(__DIR__.'/../../public/svgs/denoKV.svg'))->toBeTrue();

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('denokv');
        expect($templates)->not->toHaveKey('denoKV');
        expect($templates['denokv']['port'] ?? null)->toBe('4512');
        expect($templates['denokv']['logo'] ?? null)->toBe('svgs/denoKV.svg');
        expect($templates['denokv']['category'] ?? null)->toBe('database');

        $generatedCompose = base64_decode($templates['denokv']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/denoland/denokv:latest')
            ->toContain('SERVICE_PASSWORD_DENOKV');
    }
});
