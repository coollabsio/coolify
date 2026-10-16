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
