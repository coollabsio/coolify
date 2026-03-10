<?php

/**
 * Unit tests to verify that service templates use ${VAR} syntax
 * instead of bare $VAR for environment variable references.
 *
 * Bare $VAR syntax (e.g. AUTH_USERNAME=$SERVICE_USER_OPENCLAW) causes
 * Coolify to generate invalid .env entries like "$SERVICE_USER_OPENCLAW=",
 * breaking deployment with "unexpected character $ in variable name" errors.
 */
it('ensures service template compose files use braced env var syntax', function () {
    $templateFiles = [
        __DIR__.'/../../templates/service-templates-latest.json',
        __DIR__.'/../../templates/service-templates.json',
    ];

    foreach ($templateFiles as $templateFile) {
        $data = json_decode(file_get_contents($templateFile), true);

        foreach ($data as $templateName => $template) {
            $composeB64 = $template['compose'] ?? '';
            if (empty($composeB64)) {
                continue;
            }

            $compose = base64_decode($composeB64);

            // Match =$VAR where VAR starts with uppercase and is NOT wrapped in {}
            // This catches patterns like: KEY=$SERVICE_USER_POSTGRES
            // But not: KEY=${SERVICE_USER_POSTGRES}
            preg_match_all('/=\$(?!\{)([A-Z_][A-Z0-9_]*)/', $compose, $matches);

            expect($matches[0])
                ->toBeEmpty("Template '{$templateName}' in {$templateFile} contains bare \$VAR env references: ".implode(', ', $matches[0]).'. Use ${VAR} syntax instead.');
        }
    }
});
