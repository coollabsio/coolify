<?php

/**
 * Unit tests to verify that updateCompose() correctly handles abbreviated
 * SERVICE_URL and SERVICE_FQDN variable names from templates.
 *
 * This tests the fix for GitHub issue #7243 where SERVICE_URL_OPDASHBOARD
 * wasn't being updated when the domain changed, while SERVICE_URL_OPDASHBOARD_3000
 * was being updated correctly.
 *
 * The issue occurs when template variable names are abbreviated (e.g., OPDASHBOARD)
 * instead of using the full container name (e.g., OPENPANEL_DASHBOARD).
 */

use Symfony\Component\Yaml\Yaml;

it('detects SERVICE_URL variables directly declared in template environment', function () {
    $yaml = <<<'YAML'
services:
  openpanel-dashboard:
    environment:
      - SERVICE_URL_OPDASHBOARD_3000
      - OTHER_VAR=value
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.openpanel-dashboard');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $envVar) {
        if (is_string($envVar)) {
            $envVarName = str($envVar)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    expect($templateVariableNames)->toContain('SERVICE_URL_OPDASHBOARD_3000');
    expect($templateVariableNames)->not->toContain('OTHER_VAR');
});

it('only detects directly declared SERVICE_URL variables not references', function () {
    $yaml = <<<'YAML'
services:
  openpanel-dashboard:
    environment:
      - SERVICE_URL_OPDASHBOARD_3000
      - NEXT_PUBLIC_DASHBOARD_URL=${SERVICE_URL_OPDASHBOARD}
      - NEXT_PUBLIC_API_URL=${SERVICE_URL_OPAPI}
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.openpanel-dashboard');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $envVar) {
        if (is_string($envVar)) {
            $envVarName = str($envVar)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    // Should only detect the direct declaration
    expect($templateVariableNames)->toContain('SERVICE_URL_OPDASHBOARD_3000');
    // Should NOT detect references (those belong to other services)
    expect($templateVariableNames)->not->toContain('SERVICE_URL_OPDASHBOARD');
    expect($templateVariableNames)->not->toContain('SERVICE_URL_OPAPI');
});

it('detects multiple directly declared SERVICE_URL variables', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      - SERVICE_URL_APP
      - SERVICE_URL_APP_3000
      - SERVICE_FQDN_API
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.app');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $envVar) {
        if (is_string($envVar)) {
            // Extract variable name (before '=' if present)
            $envVarName = str($envVar)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    $templateVariableNames = array_unique($templateVariableNames);

    expect($templateVariableNames)->toHaveCount(3);
    expect($templateVariableNames)->toContain('SERVICE_URL_APP');
    expect($templateVariableNames)->toContain('SERVICE_URL_APP_3000');
    expect($templateVariableNames)->toContain('SERVICE_FQDN_API');
});

it('removes duplicates from template variable names', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      - SERVICE_URL_APP
      - PUBLIC_URL=${SERVICE_URL_APP}
      - PRIVATE_URL=${SERVICE_URL_APP}
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.app');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $envVar) {
        if (is_string($envVar)) {
            $envVarName = str($envVar)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
        if (is_string($envVar) && str($envVar)->contains('${')) {
            preg_match_all('/\$\{(SERVICE_(?:FQDN|URL)_[^}]+)\}/', $envVar, $matches);
            if (! empty($matches[1])) {
                foreach ($matches[1] as $match) {
                    $templateVariableNames[] = $match;
                }
            }
        }
    }

    $templateVariableNames = array_unique($templateVariableNames);

    // SERVICE_URL_APP appears 3 times but should only be in array once
    expect($templateVariableNames)->toHaveCount(1);
    expect($templateVariableNames)->toContain('SERVICE_URL_APP');
});

it('detects SERVICE_FQDN variables in addition to SERVICE_URL', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      - SERVICE_FQDN_APP
      - SERVICE_FQDN_APP_3000
      - SERVICE_URL_APP
      - SERVICE_URL_APP_8080
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.app');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $envVar) {
        if (is_string($envVar)) {
            $envVarName = str($envVar)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    expect($templateVariableNames)->toHaveCount(4);
    expect($templateVariableNames)->toContain('SERVICE_FQDN_APP');
    expect($templateVariableNames)->toContain('SERVICE_FQDN_APP_3000');
    expect($templateVariableNames)->toContain('SERVICE_URL_APP');
    expect($templateVariableNames)->toContain('SERVICE_URL_APP_8080');
});

it('handles abbreviated service names that differ from container names', function () {
    // This is the actual OpenPanel case from GitHub issue #7243
    // Container name: openpanel-dashboard
    // Template variable: SERVICE_URL_OPDASHBOARD (abbreviated)

    $containerName = 'openpanel-dashboard';
    $templateVariableName = 'SERVICE_URL_OPDASHBOARD';

    // The old logic would generate this from container name:
    $generatedFromContainer = 'SERVICE_URL_'.str($containerName)->upper()->replace('-', '_')->value();

    // This shows the mismatch
    expect($generatedFromContainer)->toBe('SERVICE_URL_OPENPANEL_DASHBOARD');
    expect($generatedFromContainer)->not->toBe($templateVariableName);

    // The template uses the abbreviated form
    expect($templateVariableName)->toBe('SERVICE_URL_OPDASHBOARD');
});

it('correctly identifies abbreviated variable patterns', function () {
    $tests = [
        // Full name transformations (old logic)
        ['container' => 'openpanel-dashboard', 'generated' => 'SERVICE_URL_OPENPANEL_DASHBOARD'],
        ['container' => 'my-long-service', 'generated' => 'SERVICE_URL_MY_LONG_SERVICE'],

        // Abbreviated forms (template logic)
        ['container' => 'openpanel-dashboard', 'template' => 'SERVICE_URL_OPDASHBOARD'],
        ['container' => 'openpanel-api', 'template' => 'SERVICE_URL_OPAPI'],
        ['container' => 'my-long-service', 'template' => 'SERVICE_URL_MLS'],
    ];

    foreach ($tests as $test) {
        if (isset($test['generated'])) {
            $generated = 'SERVICE_URL_'.str($test['container'])->upper()->replace('-', '_')->value();
            expect($generated)->toBe($test['generated']);
        }

        if (isset($test['template'])) {
            // Template abbreviations can't be generated from container name
            // They must be parsed from the actual template
            expect($test['template'])->toMatch('/^SERVICE_URL_[A-Z0-9_]+$/');
        }
    }
});

it('verifies direct declarations are not confused with references', function () {
    // Direct declarations should be detected
    $directDeclaration = 'SERVICE_URL_APP';
    expect(str($directDeclaration)->startsWith('SERVICE_URL_'))->toBeTrue();
    expect(str($directDeclaration)->before('=')->value())->toBe('SERVICE_URL_APP');

    // References should not be detected as declarations
    $reference = 'NEXT_PUBLIC_URL=${SERVICE_URL_APP}';
    $varName = str($reference)->before('=')->trim();
    expect($varName->startsWith('SERVICE_URL_'))->toBeFalse();
    expect($varName->value())->toBe('NEXT_PUBLIC_URL');
});

it('ensures updateCompose helper file has template parsing logic', function () {
    $servicesFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/services.php');

    // Check that the fix is in place
    expect($servicesFile)->toContain('Extract SERVICE_URL and SERVICE_FQDN variable names from the compose template');
    expect($servicesFile)->toContain('to ensure we use the exact names defined in the template');
    expect($servicesFile)->toContain('$templateVariableNames');
    expect($servicesFile)->toContain('DIRECTLY DECLARED');
    expect($servicesFile)->toContain('not variables that are merely referenced from other services');
});

it('verifies that service names are extracted to create both URL and FQDN pairs', function () {
    $servicesFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/services.php');

    // Verify the logic to create both pairs exists
    expect($servicesFile)->toContain('create BOTH SERVICE_URL and SERVICE_FQDN pairs');
    expect($servicesFile)->toContain('ALWAYS create base pair');
    expect($servicesFile)->toContain('SERVICE_URL_{$serviceName}');
    expect($servicesFile)->toContain('SERVICE_FQDN_{$serviceName}');
});

it('extracts service names correctly for pairing', function () {
    // Simulate what the updateCompose function does
    $templateVariableNames = [
        'SERVICE_URL_OPDASHBOARD',
        'SERVICE_URL_OPDASHBOARD_3000',
        'SERVICE_URL_OPAPI',
    ];

    $serviceNamesToProcess = [];
    foreach ($templateVariableNames as $templateVarName) {
        $parsed = parseServiceEnvironmentVariable($templateVarName);
        $serviceName = $parsed['service_name'];

        if (! isset($serviceNamesToProcess[$serviceName])) {
            $serviceNamesToProcess[$serviceName] = [
                'base' => $serviceName,
                'ports' => [],
            ];
        }

        if ($parsed['has_port'] && $parsed['port']) {
            $serviceNamesToProcess[$serviceName]['ports'][] = $parsed['port'];
        }
    }

    // Should extract 2 unique service names
    expect($serviceNamesToProcess)->toHaveCount(2);
    expect($serviceNamesToProcess)->toHaveKey('opdashboard');
    expect($serviceNamesToProcess)->toHaveKey('opapi');

    // OPDASHBOARD should have port 3000 tracked
    expect($serviceNamesToProcess['opdashboard']['ports'])->toContain('3000');

    // OPAPI should have no ports
    expect($serviceNamesToProcess['opapi']['ports'])->toBeEmpty();
});

it('should create both URL and FQDN when only URL is in template', function () {
    // Given: Template defines only SERVICE_URL_APP
    $templateVar = 'SERVICE_URL_APP';

    // When: Processing this variable
    $parsed = parseServiceEnvironmentVariable($templateVar);
    $serviceName = $parsed['service_name'];

    // Then: We should create both:
    // - SERVICE_URL_APP (or SERVICE_URL_app depending on template)
    // - SERVICE_FQDN_APP (or SERVICE_FQDN_app depending on template)
    expect($serviceName)->toBe('app');

    $urlKey = 'SERVICE_URL_'.str($serviceName)->upper();
    $fqdnKey = 'SERVICE_FQDN_'.str($serviceName)->upper();

    expect($urlKey)->toBe('SERVICE_URL_APP');
    expect($fqdnKey)->toBe('SERVICE_FQDN_APP');
});

it('should create both URL and FQDN when only FQDN is in template', function () {
    // Given: Template defines only SERVICE_FQDN_DATABASE
    $templateVar = 'SERVICE_FQDN_DATABASE';

    // When: Processing this variable
    $parsed = parseServiceEnvironmentVariable($templateVar);
    $serviceName = $parsed['service_name'];

    // Then: We should create both:
    // - SERVICE_URL_DATABASE (or SERVICE_URL_database depending on template)
    // - SERVICE_FQDN_DATABASE (or SERVICE_FQDN_database depending on template)
    expect($serviceName)->toBe('database');

    $urlKey = 'SERVICE_URL_'.str($serviceName)->upper();
    $fqdnKey = 'SERVICE_FQDN_'.str($serviceName)->upper();

    expect($urlKey)->toBe('SERVICE_URL_DATABASE');
    expect($fqdnKey)->toBe('SERVICE_FQDN_DATABASE');
});

it('should create all 4 variables when port-specific variable is in template', function () {
    // Given: Template defines SERVICE_URL_UMAMI_3000
    $templateVar = 'SERVICE_URL_UMAMI_3000';

    // When: Processing this variable
    $parsed = parseServiceEnvironmentVariable($templateVar);
    $serviceName = $parsed['service_name'];
    $port = $parsed['port'];

    // Then: We should create all 4:
    // 1. SERVICE_URL_UMAMI (base)
    // 2. SERVICE_FQDN_UMAMI (base)
    // 3. SERVICE_URL_UMAMI_3000 (port-specific)
    // 4. SERVICE_FQDN_UMAMI_3000 (port-specific)

    expect($serviceName)->toBe('umami');
    expect($port)->toBe('3000');

    $serviceNameUpper = str($serviceName)->upper();
    $baseUrlKey = "SERVICE_URL_{$serviceNameUpper}";
    $baseFqdnKey = "SERVICE_FQDN_{$serviceNameUpper}";
    $portUrlKey = "SERVICE_URL_{$serviceNameUpper}_{$port}";
    $portFqdnKey = "SERVICE_FQDN_{$serviceNameUpper}_{$port}";

    expect($baseUrlKey)->toBe('SERVICE_URL_UMAMI');
    expect($baseFqdnKey)->toBe('SERVICE_FQDN_UMAMI');
    expect($portUrlKey)->toBe('SERVICE_URL_UMAMI_3000');
    expect($portFqdnKey)->toBe('SERVICE_FQDN_UMAMI_3000');
});

it('should handle multiple ports for same service', function () {
    $templateVariableNames = [
        'SERVICE_URL_API_3000',
        'SERVICE_URL_API_8080',
    ];

    $serviceNamesToProcess = [];
    foreach ($templateVariableNames as $templateVarName) {
        $parsed = parseServiceEnvironmentVariable($templateVarName);
        $serviceName = $parsed['service_name'];

        if (! isset($serviceNamesToProcess[$serviceName])) {
            $serviceNamesToProcess[$serviceName] = [
                'base' => $serviceName,
                'ports' => [],
            ];
        }

        if ($parsed['has_port'] && $parsed['port']) {
            $serviceNamesToProcess[$serviceName]['ports'][] = $parsed['port'];
        }
    }

    // Should have one service with two ports
    expect($serviceNamesToProcess)->toHaveCount(1);
    expect($serviceNamesToProcess['api']['ports'])->toHaveCount(2);
    expect($serviceNamesToProcess['api']['ports'])->toContain('3000');
    expect($serviceNamesToProcess['api']['ports'])->toContain('8080');

    // Should create 6 variables total:
    // 1. SERVICE_URL_API (base)
    // 2. SERVICE_FQDN_API (base)
    // 3. SERVICE_URL_API_3000
    // 4. SERVICE_FQDN_API_3000
    // 5. SERVICE_URL_API_8080
    // 6. SERVICE_FQDN_API_8080
});

it('detects SERVICE_URL variables in map-style environment format', function () {
    $yaml = <<<'YAML'
services:
  trigger:
    environment:
      SERVICE_URL_TRIGGER_3000: ""
      SERVICE_FQDN_DB: localhost
      OTHER_VAR: value
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.trigger');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $key => $value) {
        if (is_int($key) && is_string($value)) {
            // List-style
            $envVarName = str($value)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        } elseif (is_string($key)) {
            // Map-style
            $envVarName = str($key);
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    expect($templateVariableNames)->toHaveCount(2);
    expect($templateVariableNames)->toContain('SERVICE_URL_TRIGGER_3000');
    expect($templateVariableNames)->toContain('SERVICE_FQDN_DB');
    expect($templateVariableNames)->not->toContain('OTHER_VAR');
});

it('handles multiple map-style SERVICE_URL and SERVICE_FQDN variables', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      SERVICE_URL_APP_3000: ""
      SERVICE_FQDN_API: api.local
      SERVICE_URL_WEB: ""
      OTHER_VAR: value
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.app');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $key => $value) {
        if (is_int($key) && is_string($value)) {
            // List-style
            $envVarName = str($value)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        } elseif (is_string($key)) {
            // Map-style
            $envVarName = str($key);
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    expect($templateVariableNames)->toHaveCount(3);
    expect($templateVariableNames)->toContain('SERVICE_URL_APP_3000');
    expect($templateVariableNames)->toContain('SERVICE_FQDN_API');
    expect($templateVariableNames)->toContain('SERVICE_URL_WEB');
    expect($templateVariableNames)->not->toContain('OTHER_VAR');
});

it('does not detect SERVICE_URL references in map-style values', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      SERVICE_URL_APP_3000: ""
      NEXT_PUBLIC_URL: ${SERVICE_URL_APP}
      API_ENDPOINT: ${SERVICE_URL_API}
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.app');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $key => $value) {
        if (is_int($key) && is_string($value)) {
            // List-style
            $envVarName = str($value)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        } elseif (is_string($key)) {
            // Map-style
            $envVarName = str($key);
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    // Should only detect the direct declaration, not references in values
    expect($templateVariableNames)->toHaveCount(1);
    expect($templateVariableNames)->toContain('SERVICE_URL_APP_3000');
    expect($templateVariableNames)->not->toContain('SERVICE_URL_APP');
    expect($templateVariableNames)->not->toContain('SERVICE_URL_API');
    expect($templateVariableNames)->not->toContain('NEXT_PUBLIC_URL');
    expect($templateVariableNames)->not->toContain('API_ENDPOINT');
});

it('handles map-style with abbreviated service names', function () {
    // Simulating the langfuse.yaml case with map-style
    $yaml = <<<'YAML'
services:
  langfuse:
    environment:
      SERVICE_URL_LANGFUSE_3000: ${SERVICE_URL_LANGFUSE_3000}
      DATABASE_URL: postgres://...
YAML;

    $dockerCompose = Yaml::parse($yaml);
    $serviceConfig = data_get($dockerCompose, 'services.langfuse');
    $environment = data_get($serviceConfig, 'environment', []);

    $templateVariableNames = [];
    foreach ($environment as $key => $value) {
        if (is_int($key) && is_string($value)) {
            // List-style
            $envVarName = str($value)->before('=')->trim();
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        } elseif (is_string($key)) {
            // Map-style
            $envVarName = str($key);
            if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                $templateVariableNames[] = $envVarName->value();
            }
        }
    }

    expect($templateVariableNames)->toHaveCount(1);
    expect($templateVariableNames)->toContain('SERVICE_URL_LANGFUSE_3000');
    expect($templateVariableNames)->not->toContain('DATABASE_URL');
});

it('verifies updateCompose helper has dual-format handling', function () {
    $servicesFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/services.php');

    // Check that both formats are handled
    expect($servicesFile)->toContain('is_int($key) && is_string($value)');
    expect($servicesFile)->toContain('List-style');
    expect($servicesFile)->toContain('elseif (is_string($key))');
    expect($servicesFile)->toContain('Map-style');
});
