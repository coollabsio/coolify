<?php

it('requires a mail driver before Docmost can start', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/docmost.yaml');

    expect($compose)
        ->toContain('MAIL_DRIVER=${MAIL_DRIVER:?}')
        ->not->toContain('MAIL_DRIVER=${MAIL_DRIVER}');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $generatedCompose = base64_decode($templates['docmost']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('MAIL_DRIVER=${MAIL_DRIVER:?}')
            ->not->toContain('MAIL_DRIVER=${MAIL_DRIVER}');
    }
});
