<?php

it('includes a HashiCorp Vault one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/vault.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('hashicorp/vault:${VAULT_VERSION:-latest}')
        ->toContain('SERVICE_URL_VAULT_8200')
        ->toContain('VAULT_API_ADDR=${SERVICE_URL_VAULT_8200}')
        ->toContain('"disable_mlock":true')
        ->toContain('"storage":{"file":{"path":"/vault/file"}}')
        ->toContain('vault-data:/vault/file')
        ->toContain('/v1/sys/health?standbyok=true&sealedcode=200&uninitcode=200');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('vault');
        expect($templates['vault']['port'] ?? null)->toBe('8200');
        expect($templates['vault']['category'] ?? null)->toBe('security');

        $generatedCompose = base64_decode($templates['vault']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('hashicorp/vault:${VAULT_VERSION:-latest}')
            ->toContain($templateFile === 'service-templates.json'
                ? 'VAULT_API_ADDR=${SERVICE_FQDN_VAULT_8200}'
                : 'VAULT_API_ADDR=${SERVICE_URL_VAULT_8200}');
    }
});
