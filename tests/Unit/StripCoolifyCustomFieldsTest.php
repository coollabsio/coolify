<?php

use function PHPUnit\Framework\assertEquals;

test('removes exclude_from_hc from service level', function () {
    $yaml = [
        'services' => [
            'web' => [
                'image' => 'nginx:latest',
                'exclude_from_hc' => true,
                'ports' => ['80:80'],
            ],
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    assertEquals('nginx:latest', $result['services']['web']['image']);
    assertEquals(['80:80'], $result['services']['web']['ports']);
    expect($result['services']['web'])->not->toHaveKey('exclude_from_hc');
});

test('removes content from volume level', function () {
    $yaml = [
        'services' => [
            'app' => [
                'image' => 'php:8.4',
                'volumes' => [
                    [
                        'type' => 'bind',
                        'source' => './config.xml',
                        'target' => '/app/config.xml',
                        'content' => '<?xml version="1.0"?><config></config>',
                    ],
                ],
            ],
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    expect($result['services']['app']['volumes'][0])->toHaveKeys(['type', 'source', 'target']);
    expect($result['services']['app']['volumes'][0])->not->toHaveKey('content');
});

test('removes isDirectory from volume level', function () {
    $yaml = [
        'services' => [
            'app' => [
                'image' => 'node:20',
                'volumes' => [
                    [
                        'type' => 'bind',
                        'source' => './data',
                        'target' => '/app/data',
                        'isDirectory' => true,
                    ],
                ],
            ],
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    expect($result['services']['app']['volumes'][0])->toHaveKeys(['type', 'source', 'target']);
    expect($result['services']['app']['volumes'][0])->not->toHaveKey('isDirectory');
});

test('removes is_directory from volume level', function () {
    $yaml = [
        'services' => [
            'app' => [
                'image' => 'python:3.12',
                'volumes' => [
                    [
                        'type' => 'bind',
                        'source' => './logs',
                        'target' => '/var/log/app',
                        'is_directory' => true,
                    ],
                ],
            ],
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    expect($result['services']['app']['volumes'][0])->toHaveKeys(['type', 'source', 'target']);
    expect($result['services']['app']['volumes'][0])->not->toHaveKey('is_directory');
});

test('removes all custom fields together', function () {
    $yaml = [
        'services' => [
            'web' => [
                'image' => 'nginx:latest',
                'exclude_from_hc' => true,
                'volumes' => [
                    [
                        'type' => 'bind',
                        'source' => './config.xml',
                        'target' => '/etc/nginx/config.xml',
                        'content' => '<config></config>',
                        'isDirectory' => false,
                    ],
                    [
                        'type' => 'bind',
                        'source' => './data',
                        'target' => '/var/www/data',
                        'is_directory' => true,
                    ],
                ],
            ],
            'worker' => [
                'image' => 'worker:latest',
                'exclude_from_hc' => true,
            ],
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    // Verify service-level custom fields removed
    expect($result['services']['web'])->not->toHaveKey('exclude_from_hc');
    expect($result['services']['worker'])->not->toHaveKey('exclude_from_hc');

    // Verify volume-level custom fields removed
    expect($result['services']['web']['volumes'][0])->not->toHaveKey('content');
    expect($result['services']['web']['volumes'][0])->not->toHaveKey('isDirectory');
    expect($result['services']['web']['volumes'][1])->not->toHaveKey('is_directory');

    // Verify standard fields preserved
    assertEquals('nginx:latest', $result['services']['web']['image']);
    assertEquals('worker:latest', $result['services']['worker']['image']);
});

test('preserves standard Docker Compose fields', function () {
    $yaml = [
        'services' => [
            'db' => [
                'image' => 'postgres:16',
                'environment' => [
                    'POSTGRES_DB' => 'mydb',
                    'POSTGRES_USER' => 'user',
                ],
                'ports' => ['5432:5432'],
                'volumes' => [
                    'db-data:/var/lib/postgresql/data',
                ],
                'healthcheck' => [
                    'test' => ['CMD', 'pg_isready'],
                    'interval' => '5s',
                ],
                'restart' => 'unless-stopped',
                'networks' => ['backend'],
            ],
        ],
        'networks' => [
            'backend' => [
                'driver' => 'bridge',
            ],
        ],
        'volumes' => [
            'db-data' => null,
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    // All standard fields should be preserved
    expect($result)->toHaveKeys(['services', 'networks', 'volumes']);
    expect($result['services']['db'])->toHaveKeys([
        'image', 'environment', 'ports', 'volumes',
        'healthcheck', 'restart', 'networks',
    ]);
    assertEquals('postgres:16', $result['services']['db']['image']);
    assertEquals(['5432:5432'], $result['services']['db']['ports']);
});

test('handles missing services gracefully', function () {
    $yaml = [
        'version' => '3.8',
    ];

    $result = stripCoolifyCustomFields($yaml);

    expect($result)->toBe($yaml);
});

test('handles missing volumes in service gracefully', function () {
    $yaml = [
        'services' => [
            'app' => [
                'image' => 'nginx:latest',
                'exclude_from_hc' => true,
            ],
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    expect($result['services']['app'])->not->toHaveKey('exclude_from_hc');
    expect($result['services']['app'])->not->toHaveKey('volumes');
    assertEquals('nginx:latest', $result['services']['app']['image']);
});

test('handles traccar.yaml example with multiline content', function () {
    $yaml = [
        'services' => [
            'traccar' => [
                'image' => 'traccar/traccar:latest',
                'volumes' => [
                    [
                        'type' => 'bind',
                        'source' => './srv/traccar/conf/traccar.xml',
                        'target' => '/opt/traccar/conf/traccar.xml',
                        'content' => "<?xml version='1.0' encoding='UTF-8'?>\n<!DOCTYPE properties SYSTEM 'http://java.sun.com/dtd/properties.dtd'>\n<properties>\n    <entry key='config.default'>./conf/default.xml</entry>\n</properties>",
                    ],
                ],
            ],
        ],
    ];

    $result = stripCoolifyCustomFields($yaml);

    expect($result['services']['traccar']['volumes'][0])->toHaveKeys(['type', 'source', 'target']);
    expect($result['services']['traccar']['volumes'][0])->not->toHaveKey('content');
    assertEquals('./srv/traccar/conf/traccar.xml', $result['services']['traccar']['volumes'][0]['source']);
});
