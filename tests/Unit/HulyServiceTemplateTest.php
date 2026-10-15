<?php

it('includes a production-ready Huly one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/huly.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('image: "nginx:1.27.4-alpine"')
        ->toContain('SERVICE_URL_HULY_80')
        ->toContain('cockroachdb/cockroach:v24.2.11')
        ->toContain('docker.redpanda.com/redpandadata/redpanda:v24.3.6')
        ->toContain('minio/minio:RELEASE.2024-11-07T00-52-28Z')
        ->toContain('elasticsearch:7.14.2')
        ->toContain('hardcoreeng/front:v0.7.426')
        ->toContain('hardcoreeng/account:v0.7.426')
        ->toContain('hardcoreeng/transactor:v0.7.426')
        ->toContain('hardcoreeng/collaborator:v0.7.426')
        ->toContain('hardcoreeng/workspace:v0.7.426')
        ->toContain('hardcoreeng/fulltext:v0.7.426')
        ->toContain('hardcoreeng/rekoni-service:v0.7.426')
        ->toContain('hardcoreeng/stats:v0.7.426')
        ->toContain('SERVICE_PASSWORD_64_SECRET')
        ->toContain('SERVICE_PASSWORD_COCKROACH')
        ->toContain('SERVICE_PASSWORD_REDPANDA')
        ->toContain('SERVICE_PASSWORD_MINIO')
        ->toContain('COLLABORATOR_URL=wss://${SERVICE_FQDN_HULY_80}/_collaborator')
        ->toContain('TRANSACTOR_URL=ws://transactor:3333;wss://${SERVICE_FQDN_HULY_80}/_transactor');
});

it('ships the Huly service icon from the public and root svgs paths', function () {
    expect(__DIR__.'/../../public/svgs/huly.svg')->toBeFile();
    expect(__DIR__.'/../../svgs/huly.svg')->toBeFile();
});
