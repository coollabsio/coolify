<?php

use Symfony\Component\Yaml\Yaml;

it('includes a production-ready Zammad one-click service template', function () {
    $source = file_get_contents(__DIR__.'/../../templates/compose/zammad.yaml');
    $compose = Yaml::parse($source);
    $services = $compose['services'];
    $sharedEnvironment = $compose['x-shared']['zammad-service']['environment'];
    $zammad = $services['zammad'];

    expect($source)
        ->toContain('# documentation: https://docs.zammad.org/en/latest/install/docker-compose.html')
        ->toContain('# category: helpdesk')
        ->toContain('# logo: svgs/zammad.svg')
        ->toContain('# port: 8080')
        ->and(array_keys($services))->toBe([
            'zammad-backup',
            'zammad-elasticsearch',
            'zammad-init',
            'zammad-memcached',
            'zammad',
            'zammad-postgresql',
            'zammad-railsserver',
            'zammad-redis',
            'zammad-scheduler',
            'zammad-websocket',
        ])
        ->and($services['zammad-backup']['image'])->toBe('ghcr.io/zammad/zammad:7.1.3-0006')
        ->and($services['zammad-elasticsearch']['image'])->toBe('elasticsearch:9.5.2')
        ->and($services['zammad-memcached']['image'])->toBe('memcached:1.6.45-alpine')
        ->and($services['zammad-postgresql']['image'])->toBe('postgres:17.11-alpine')
        ->and($services['zammad-redis']['image'])->toBe('redis:8.10.1-alpine')
        ->and($services['zammad-init']['exclude_from_hc'])->toBeTrue()
        ->and($services['zammad-init']['restart'])->toBe('on-failure')
        ->and($zammad['expose'])->toBe(['8080'])
        ->and($zammad)->not->toHaveKey('ports')
        ->and($zammad['environment'])->toHaveKey('SERVICE_URL_ZAMMAD_8080', null)
        ->and($zammad['healthcheck']['test'])->toBe(['CMD', 'curl', '-sf', 'http://127.0.0.1:8080/'])
        ->and($sharedEnvironment['ZAMMAD_FQDN'])->toBe('${SERVICE_FQDN_ZAMMAD}')
        ->and($sharedEnvironment)->toHaveKey('S3_URL', null)
        ->and($sharedEnvironment['POSTGRESQL_PASS'])->toBe('${SERVICE_PASSWORD_POSTGRES}')
        ->and($services['zammad-postgresql']['environment']['POSTGRES_PASSWORD'])->toBe('${SERVICE_PASSWORD_POSTGRES}')
        ->and(array_keys($compose['volumes']))->toBe([
            'elasticsearch-data',
            'postgresql-data',
            'redis-data',
            'zammad-backup',
            'zammad-storage',
        ]);
});

it('health checks every long-running Zammad container', function () {
    $compose = Yaml::parse(file_get_contents(__DIR__.'/../../templates/compose/zammad.yaml'));

    $healthCheckedServices = array_diff(array_keys($compose['services']), ['zammad-init']);

    $missing = array_values(array_filter(
        $healthCheckedServices,
        fn (string $name): bool => blank($compose['services'][$name]['healthcheck']['test'] ?? null),
    ));

    expect($missing)->toBe([]);

    expect($compose['services']['zammad-backup']['healthcheck']['test'])->toBe(['CMD', 'pgrep', '-f', 'backup.sh'])
        ->and($compose['services']['zammad-scheduler']['healthcheck']['test'])->toBe(['CMD', 'pgrep', '-f', 'background-worker'])
        ->and($compose['services']['zammad-websocket']['healthcheck']['test'])->toBe(['CMD', 'bash', '-c', 'exec 3<>/dev/tcp/127.0.0.1/6042'])
        ->and($compose['services']['zammad-elasticsearch']['healthcheck']['test'])->toBe(['CMD', 'curl', '-sf', 'http://127.0.0.1:9200/_cluster/health']);
});

it('ships the official Zammad service icon', function () {
    $document = new DOMDocument;

    expect($document->load(__DIR__.'/../../public/svgs/zammad.svg', LIBXML_NONET))->toBeTrue()
        ->and($document->documentElement?->getAttribute('viewBox'))->toBe('0 0 42 38');
});
