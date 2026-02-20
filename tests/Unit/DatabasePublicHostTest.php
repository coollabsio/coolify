<?php

use App\Models\ServiceDatabase;
use App\Models\StandalonePostgresql;

test('standalone database external url prefers configured public host', function () {
    $database = new StandalonePostgresql;
    $database->forceFill([
        'is_public' => true,
        'public_port' => 15432,
        'public_host' => 'https://db.example.com/some/path',
        'postgres_user' => 'postgres',
        'postgres_password' => 'secret',
        'postgres_db' => 'app',
        'enable_ssl' => false,
    ]);

    $database->setRelation('destination', new class
    {
        public object $server;

        public function __construct()
        {
            $this->server = new class
            {
                public string $getIp = '203.0.113.10';
            };
        }
    });

    expect($database->external_db_url)->toContain('@db.example.com:15432/');
});

test('service database url uses public host when configured', function () {
    $database = new ServiceDatabase;
    $database->forceFill([
        'public_port' => 12345,
        'public_host' => 'http://cache.example.com/metrics',
    ]);

    $database->setRelation('service', new class
    {
        public object $server;

        public function __construct()
        {
            $this->server = new class
            {
                public string $ip = '198.51.100.5';

                public function isLocalhost(): bool
                {
                    return false;
                }
            };
        }
    });

    expect($database->getServiceDatabaseUrl())->toBe('cache.example.com:12345');
});

test('service database url falls back to server ip when public host is missing', function () {
    $database = new ServiceDatabase;
    $database->forceFill([
        'public_port' => 12345,
        'public_host' => null,
    ]);

    $database->setRelation('service', new class
    {
        public object $server;

        public function __construct()
        {
            $this->server = new class
            {
                public string $ip = '198.51.100.5';

                public function isLocalhost(): bool
                {
                    return false;
                }
            };
        }
    });

    expect($database->getServiceDatabaseUrl())->toBe('198.51.100.5:12345');
});
