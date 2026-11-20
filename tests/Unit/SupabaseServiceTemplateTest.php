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

it('uses one basic auth realm for every Studio path', function () {
    expect(supabaseTemplateFile('./volumes/api/envoy/lds.template.yaml'))
        ->toContain('local_reply_config:')
        ->toContain("value: 'Basic realm=\"Supabase Studio\"'")
        ->toContain('append_action: OVERWRITE_IF_EXISTS');
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

it('caps Edge Functions memory and reclaims idle workers under pressure', function () {
    $functions = supabaseTemplate()['services']['supabase-edge-functions'];

    expect($functions['mem_limit'])->toBe('${FUNCTIONS_MEMORY_LIMIT:-2g}')
        ->and($functions['environment'])->toContain('FUNCTIONS_MEMORY_LIMIT=${FUNCTIONS_MEMORY_LIMIT:-2g}')
        ->and(supabaseTemplateFile('./volumes/functions/main/index.ts'))->toContain("import { startMemoryPressureLoop } from './memory-pressure.ts'")
        ->and(supabaseTemplateFile('./volumes/functions/main/memory-pressure.ts'))->toContain('EdgeRuntime.userWorkers.tryCleanupIdleWorkers');
});

it('leaves CORS on the functions route to the functions', function () {
    $listeners = supabaseTemplateFile('./volumes/api/envoy/lds.template.yaml');
    $functionsRoute = substr($listeners, strpos($listeners, 'prefix: /functions/v1/'), 2000);

    expect($functionsRoute)->toContain("envoy.filters.http.cors:\n")
        ->and($listeners)->toContain('["access-control-allow-origin"] = "*",')
        ->and(supabaseTemplateFile('./volumes/functions/main/index.ts'))->toContain('...ERROR_CORS_HEADERS,');
});

it('drops removed upstream hosts at the next DNS refresh', function () {
    $clusters = supabaseTemplateFile('./volumes/api/envoy/cds.yaml');

    expect(substr_count($clusters, 'ignore_health_on_host_removal: true'))
        ->toBe(substr_count($clusters, 'health_checks:'))
        ->toBeGreaterThan(0);
});

it('collects logs only from its own stack', function () {
    expect(supabaseTemplateFile('./volumes/logs/vector.yml'))
        ->toContain('include_labels:')
        ->toContain("- 'com.docker.compose.project=\${COOLIFY_RESOURCE_UUID?COOLIFY_RESOURCE_UUID is required}'");
});

it('lets Vector expand the variables its config is written with', function () {
    $vector = supabaseTemplate()['services']['supabase-vector'];

    expect(supabaseTemplateFile('./volumes/logs/vector.yml'))->toContain('${LOGFLARE_PUBLIC_ACCESS_TOKEN')
        ->and($vector['environment'])->toContain('VECTOR_DANGEROUSLY_ALLOW_ENV_VAR_INTERPOLATION=true');
});

it('keeps Logflare below 1.52, which breaks Studio log queries on the Postgres backend', function () {
    $tag = explode(':', supabaseTemplate()['services']['supabase-analytics']['image'])[1];

    expect(version_compare($tag, '1.52.0', '<'))->toBeTrue();
});

it('runs Realtime with the patched cowboy, cowlib and ecto from v2.158.2', function () {
    $tag = ltrim(explode(':', supabaseTemplate()['services']['realtime-dev']['image'])[1], 'v');

    expect(version_compare($tag, '2.158.2', '>='))->toBeTrue();
});

it('runs Postgres 17.11 or later, with the 17.7 to 17.11 security fixes', function () {
    $tag = explode(':', supabaseTemplate()['services']['supabase-db']['image'])[1];

    expect(version_compare($tag, '17.11', '>='))->toBeTrue();
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

it('starts Vector after Logflare is healthy, without a dependency cycle', function () {
    $services = supabaseTemplate()['services'];

    expect($services['supabase-vector']['depends_on']['supabase-analytics']['condition'] ?? null)->toBe('service_healthy');

    $visit = function (string $name, array $path) use (&$visit, $services): void {
        expect($path)->not->toContain($name, 'Dependency cycle: '.implode(' -> ', [...$path, $name]));
        foreach (array_keys($services[$name]['depends_on'] ?? []) as $dependency) {
            $visit($dependency, [...$path, $name]);
        }
    };

    foreach (array_keys($services) as $name) {
        $visit($name, []);
    }
});
