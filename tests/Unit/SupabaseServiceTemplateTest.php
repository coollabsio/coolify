<?php

use Symfony\Component\Yaml\Yaml;

function supabaseTemplate(): array
{
    return Yaml::parse(file_get_contents(__DIR__.'/../../templates/compose/supabase.yaml'));
}

function supabaseTemplateFile(string $source): string
{
    foreach (supabaseTemplate()['services'] as $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            if (is_array($volume) && ($volume['source'] ?? null) === $source) {
                return $volume['content'];
            }
        }
    }

    throw new RuntimeException("{$source} is not defined in the Supabase template");
}

it('declares SERVICE_PASSWORD_JWT before the Supabase keys that are signed with it', function () {
    $firstService = array_values(supabaseTemplate()['services'])[0];
    $environment = implode("\n", $firstService['environment']);

    expect(strpos($environment, '${SERVICE_PASSWORD_JWT}'))->toBeInt()
        ->toBeLessThan(strpos($environment, '${SERVICE_SUPABASEANON_KEY}'))
        ->toBeLessThan(strpos($environment, '${SERVICE_SUPABASESERVICE_KEY}'));
});

it('blocks the Realtime management API at the gateway', function () {
    $listeners = supabaseTemplateFile('./volumes/api/envoy/lds.template.yaml');

    expect($listeners)
        ->toContain('prefix: /realtime/v1/api/tenants')
        ->toContain('prefix: /realtime/v1/api/openapi');
});

it('does not expose the JWT secret as a Postgres setting', function () {
    $services = supabaseTemplate()['services'];

    expect(supabaseTemplateFile('./volumes/db/jwt.sql'))->not->toContain('jwt_secret')
        ->and(implode("\n", $services['supabase-rest']['environment']))->not->toContain('PGRST_APP_SETTINGS_JWT_SECRET');
});

it('does not expose the storage schema through PostgREST by default', function () {
    $services = supabaseTemplate()['services'];

    expect($services['supabase-rest']['environment'])->toContain('PGRST_DB_SCHEMAS=${PGRST_DB_SCHEMAS:-public,graphql_public}')
        ->and($services['supabase-studio']['environment'])->toContain('PGRST_DB_SCHEMAS=${PGRST_DB_SCHEMAS:-public,graphql_public}');
});

it('lets database webhooks serialize rows with extension types', function () {
    expect(supabaseTemplateFile('./volumes/db/webhooks.sql'))
        ->toContain('GRANT USAGE ON SCHEMA extensions TO supabase_functions_admin;');
});

it('ships the import map used by the Edge Functions main worker', function () {
    expect(supabaseTemplateFile('./volumes/functions/main/index.ts'))->toContain('/home/deno/functions/deno.jsonc')
        ->and(supabaseTemplateFile('./volumes/functions/deno.jsonc'))->toContain('@supabase/server');
});

it('collects logs only from its own stack', function () {
    expect(supabaseTemplateFile('./volumes/logs/vector.yml'))
        ->toContain('include_labels:')
        ->toContain("- 'com.docker.compose.project=\${COOLIFY_RESOURCE_UUID}'");
});

it('verifies asymmetric keys when JWT_KEYS and JWT_JWKS are set', function () {
    $services = supabaseTemplate()['services'];

    expect($services['supabase-auth']['environment'])->toContain('GOTRUE_JWT_KEYS=${JWT_KEYS:-[]}')
        ->and($services['supabase-rest']['environment'])->toContain('PGRST_JWT_SECRET=${PGRST_JWT_SECRET:-${SERVICE_PASSWORD_JWT}}')
        ->and($services['realtime-dev']['environment'])->toContain('API_JWT_JWKS=${JWT_JWKS:-{"keys":[]}}')
        ->and($services['supabase-storage']['environment'])->toContain('JWT_JWKS=${JWT_JWKS:-{"keys":[]}}')
        ->and($services['supabase-edge-functions']['environment'])->toContain('SUPABASE_JWKS=${JWT_JWKS:-{"keys":[]}}');
});

it('uses one default per variable, because Coolify stores the first default as the value', function () {
    $defaults = [];

    foreach (supabaseTemplate()['services'] as $service) {
        foreach ($service['environment'] ?? [] as $entry) {
            $offset = 0;
            while (($start = strpos($entry, '${', $offset)) !== false) {
                $depth = 0;
                for ($end = $start + 1; $end < strlen($entry); $end++) {
                    $depth += match ($entry[$end]) {
                        '{' => 1,
                        '}' => -1,
                        default => 0,
                    };
                    if ($depth === 0) {
                        break;
                    }
                }
                preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(.*)$/s', substr($entry, $start + 2, $end - $start - 2), $match);
                $defaults[$match[1]][$match[2]] = true;
                $offset = $start + 2;
            }
        }
    }

    expect(array_keys(array_filter($defaults, fn (array $variants): bool => count($variants) > 1)))->toBe([]);
});
