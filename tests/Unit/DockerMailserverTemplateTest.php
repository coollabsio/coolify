<?php

use Symfony\Component\Yaml\Yaml;

it('keeps the docker mailserver compose template parseable with required defaults', function () {
    $templatePath = base_path('templates/compose/docker-mailserver.yaml');

    expect(is_file($templatePath))->toBeTrue();

    $content = file_get_contents($templatePath);
    expect($content)->toContain('# category: email')
        ->toContain('# logo: svgs/docker-mailserver.svg')
        ->toContain('docker-mailserver');

    $compose = Yaml::parse($content);

    expect($compose)->toBeArray()
        ->and($compose)->toHaveKey('services.mailserver');

    $mailserver = $compose['services']['mailserver'];

    expect($mailserver['ports'] ?? [])->toContain('25:25', '143:143', '465:465', '587:587', '993:993');

    $environment = $mailserver['environment'] ?? [];
    expect($environment)->toContain(
        'SERVICE_URL_MAILSERVER_80',
        'ENABLE_IMAP=${ENABLE_IMAP:-1}',
        'SSL_TYPE=${SSL_TYPE:-letsencrypt}',
        'POSTMASTER_ADDRESS=${POSTMASTER_ADDRESS}'
    );

    $volumeSources = [];
    foreach ($mailserver['volumes'] ?? [] as $volume) {
        if (is_array($volume) && ($volume['type'] ?? null) === 'bind') {
            $volumeSources[] = $volume['source'] ?? null;
            expect($volume['content'] ?? null)->toBe('');
        }
    }

    expect($volumeSources)->toContain(
        './postfix-main.cf',
        './postfix-master.cf',
        './postfix-accounts.cf',
        './postfix-virtual.cf',
        './dovecot.cf'
    );
});

it('keeps the docker mailserver svg valid xml', function () {
    $svgPath = base_path('public/svgs/docker-mailserver.svg');

    expect(is_file($svgPath))->toBeTrue();

    libxml_use_internal_errors(true);
    $svg = simplexml_load_string(file_get_contents($svgPath));

    expect($svg)->not->toBeFalse()
        ->and($svg->getName())->toBe('svg');

    libxml_clear_errors();
});
